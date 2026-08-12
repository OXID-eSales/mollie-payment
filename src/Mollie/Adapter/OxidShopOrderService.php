<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Adapter;

use DateTimeImmutable;
use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Field;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Adapter\Exception\ShopOrderException;
use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentBase\Adapter\Response\OrderResponse;
use OxidEsales\PaymentBase\Adapter\ShopOrderServiceInterface;
use Throwable;

/**
 * OXID implementation of payment-base ShopOrderServiceInterface for the Mollie module.
 *
 * Two order operations the smart-contract flow needs:
 * - `createOrder()` hydrates an OXID {@see Order} from the session basket/user and returns the
 *   order number the checkout flow passes to Mollie as the payment description (early order).
 * - `deleteNotFinishedOrder()` cancels the NOT_FINISHED order (storno) when the customer aborts
 *   at Mollie. The order is retained with OXSTORNO=1 / OXTRANSSTATUS='CANCELLED' to preserve the
 *   order-number sequence. Ported from PayPal — the smart-contract orchestration lives in
 *   payment-base; this only does OXID CRUD.
 */
class OxidShopOrderService implements ShopOrderServiceInterface
{
    public function createOrder(CreateOrderRequest $request): OrderResponse
    {
        try {
            [$basket, $user] = $this->validateBasketAndUser($request);

            if ($request->orderRemark !== null) {
                Registry::getSession()->setVariable('ordRem', $request->orderRemark);
            }

            /** @var Order $order */
            $order = oxNew(Order::class);
            /** @var int $orderState */
            $orderState = $order->finalizeOrder($basket, $user, false);

            $this->validateOrderState($orderState, $request, $basket);
            $this->setOrderFieldsAfterCreation($order, $request);

            return $this->buildOrderResponse($order, $basket, $user, $orderState, $request);
        } catch (ShopOrderException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ShopOrderException(
                message: 'Unexpected error during order creation: ' . $e->getMessage(),
                errorCode: 'unexpected_error',
                context: [
                    'exception_class' => $e::class,
                    'session_id' => $request->sessionId,
                ],
                previous: $e,
            );
        }
    }

    public function deleteNotFinishedOrder(string $orderId): bool
    {
        /** @var Order $order */
        $order = oxNew(Order::class);
        if (!$order->load($orderId)) {
            return false;
        }
        if ($order->getFieldData('oxtransstatus') !== 'NOT_FINISHED') {
            return false;
        }

        $this->resetVouchersForOrder($orderId);

        $order->oxorder__oxstorno = new Field(1, Field::T_RAW);
        $order->oxorder__oxtransstatus = new Field('CANCELLED', Field::T_RAW);
        $order->save();

        return true;
    }

    /**
     * @return array{Basket, User}
     */
    private function validateBasketAndUser(CreateOrderRequest $request): array
    {
        /** @var Basket|null $basket */
        $basket = Registry::getSession()->getBasket();
        if (!$basket) {
            throw new ShopOrderException(
                message: 'Basket not found in session',
                errorCode: 'basket_not_found',
                context: ['session_id' => $request->sessionId],
            );
        }

        /** @var User|null $user */
        $user = $basket->getBasketUser();
        if (!$user || !$user->getId()) {
            throw new ShopOrderException(
                message: 'User not found',
                errorCode: 'user_not_found',
                context: ['user_id' => $request->userId],
            );
        }

        return [$basket, $user];
    }

    private function validateOrderState(int $orderState, CreateOrderRequest $request, Basket $basket): void
    {
        if (in_array($orderState, [Order::ORDER_STATE_OK, Order::ORDER_STATE_ORDEREXISTS], true)) {
            return;
        }

        $errorCode = $this->mapOrderStateToErrorCode($orderState);
        Registry::getLogger()->error('OxidShopOrderService(Mollie): order finalization failed', [
            'order_state' => $orderState,
            'error_code' => $errorCode,
            'session_id' => $request->sessionId,
            'user_id' => $request->userId,
            'payment_id' => $request->paymentId,
            'basket_count' => $basket->getProductsCount(),
        ]);
        throw new ShopOrderException(
            message: 'Order finalization failed with state: ' . $orderState . ' (' . $errorCode . ')',
            errorCode: $errorCode,
            context: [
                'order_state' => $orderState,
                'session_id' => $request->sessionId,
            ],
        );
    }

    private function setOrderFieldsAfterCreation(Order $order, CreateOrderRequest $request): void
    {
        $order->oxorder__oxfolder = new Field('ORDERFOLDER_NEW', Field::T_RAW);

        if ($request->paymentTransactionId !== null) {
            $order->oxorder__oxtransid = new Field($request->paymentTransactionId, Field::T_RAW);
        }
        if ($request->initialStatus !== null) {
            $order->oxorder__oxtransstatus = new Field($request->initialStatus, Field::T_RAW);
        }

        $order->save();
        $order->setOrderNumber(); // @phpstan-ignore method.notFound
    }

    private function buildOrderResponse(
        Order $order,
        Basket $basket,
        User $user,
        int $orderState,
        CreateOrderRequest $request,
    ): OrderResponse {
        $price = $basket->getPrice();
        // Sprint 11 Story 11 (F17): report what the basket actually says, or nothing.
        $currencyName = OxidCurrencyReader::codeFrom($basket->getBasketCurrency());
        if ($currencyName === null) {
            Registry::getLogger()->warning(
                '[OxidShopOrderService] basket currency could not be read for the order response',
                ['orderId' => (string) $order->getId()],
            );
            $currencyName = '';
        }

        return new OrderResponse(
            orderId: (string) $order->getId(),
            orderNumber: $this->getIntField($order, 'oxordernr'),
            userId: (string) $user->getId(),
            totalAmount: $price ? (float) $price->getBruttoPrice() : 0.0, // @phpstan-ignore ternary.alwaysTrue
            currency: $currencyName,
            status: $this->mapOrderStateToStatus($orderState),
            paymentId: $request->paymentId,
            paymentTransactionId: $request->paymentTransactionId,
            createdAt: $this->getOrderCreationDate($order),
            metadata: $request->metadata,
            shopData: [
                'oxid_order_state' => $orderState,
                'oxid_order_id' => $order->getId(),
                'oxid_order_nr' => $order->getFieldData('oxordernr'),
            ],
        );
    }

    private function resetVouchersForOrder(string $orderId): void
    {
        $db = DatabaseProvider::getDb();
        $db->execute(
            'UPDATE oxvouchers SET OXORDERID = \'\', OXUSERID = \'\', OXDISCOUNT = 0, '
            . 'OXDATEUSED = NULL, OXRESERVED = 0 WHERE OXORDERID = ?',
            [$orderId],
        );
    }

    private function mapOrderStateToStatus(int $orderState): string
    {
        return match ($orderState) {
            Order::ORDER_STATE_OK, Order::ORDER_STATE_ORDEREXISTS, Order::ORDER_STATE_MAILINGERROR => 'completed',
            Order::ORDER_STATE_PAYMENTERROR => 'payment_error',
            Order::ORDER_STATE_BELOWMINPRICE => 'below_minimum',
            Order::ORDER_STATE_INVALIDPAYMENT => 'invalid_payment',
            Order::ORDER_STATE_INVALIDDELIVERY, Order::ORDER_STATE_INVALIDDELADDRESSCHANGED => 'invalid_delivery',
            Order::ORDER_STATE_VOUCHERERROR => 'voucher_error',
            default => 'unknown',
        };
    }

    private function mapOrderStateToErrorCode(int $orderState): string
    {
        return match ($orderState) {
            Order::ORDER_STATE_PAYMENTERROR => 'payment_error',
            Order::ORDER_STATE_BELOWMINPRICE => 'below_minimum_price',
            Order::ORDER_STATE_INVALIDPAYMENT => 'invalid_payment_method',
            Order::ORDER_STATE_INVALIDDELIVERY => 'invalid_delivery_method',
            Order::ORDER_STATE_INVALIDDELADDRESSCHANGED => 'invalid_delivery_address',
            Order::ORDER_STATE_VOUCHERERROR => 'voucher_error',
            default => 'order_creation_failed',
        };
    }

    private function getOrderCreationDate(Order $order): DateTimeImmutable
    {
        $dateStr = $order->getFieldData('oxorderdate');
        if (is_string($dateStr) && $dateStr !== '') {
            try {
                return new DateTimeImmutable($dateStr);
            } catch (Throwable $e) {
                // Sprint 11 Story 8: substituting "now" for an unparseable order date silently means
                // the recorded creation time is simply wrong, with nothing to say so. Low impact,
                // but the rule is the same as everywhere else in this sprint — safe or loud.
                Registry::getLogger()->warning(
                    '[OxidShopOrderService] unparseable oxorderdate; substituting the current time',
                    ['orderId' => (string) $order->getId(), 'value' => $dateStr, 'error' => $e->getMessage()],
                );
            }
        }
        return new DateTimeImmutable();
    }

    private function getIntField(Order $order, string $fieldName): int
    {
        $value = $order->getFieldData($fieldName);
        return is_numeric($value) ? (int) $value : 0;
    }
}
