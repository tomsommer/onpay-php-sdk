<?php

declare(strict_types=1);

namespace OnPay\API;


use OnPay\API\Subscription\DetailedSubscription;
use OnPay\API\Subscription\SimpleSubscription;
use OnPay\API\Subscription\SubscriptionCollection;
use OnPay\API\Transaction\DetailedTransaction;
use OnPay\API\Exception\ApiException;
use OnPay\API\Util\Pagination;
use OnPay\Http\ApiClientInterface;
use OnPay\API\Util\ResponseParser;

class SubscriptionService
{
    private $api;

    /**
     * @internal Should never be called outside library
     * SubscriptionService constructor.
     * @param ApiClientInterface $api
     */
    public function __construct(ApiClientInterface $api)
    {
        $this->api = $api;
    }

    /**
     * Get list of subscriptions
     * @param null $page
     * @param null $pageSize
     * @param null $orderBy
     * @param null $query
     * @param null $status
     * @param null $dateAfter
     * @param null $dateBefore
     * @param string $direction
     * @return SubscriptionCollection
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getSubscriptions($page = null, $pageSize = null, $orderBy = null, $query = null, $status = null, $dateAfter = null, $dateBefore = null, $direction = 'DESC')  {
        $direction = strtoupper($direction);
        if ($direction !== 'ASC') {
            $direction = 'DESC';
        }
        $queryString = http_build_query(
            [
                'page' => $page,
                'page_size' => $pageSize,
                'order_by' => $orderBy,
                'query' => $query,
                'status' => $status,
                'date_after' => $dateAfter,
                'date_before' => $dateBefore,
                'direction' => $direction
            ]);

        $results = $this->api->request('GET', 'subscription/?' . $queryString);
        $subscriptions = [];

        foreach (ResponseParser::collection($results) as $result) {
            $subscription = new SimpleSubscription($result);
            $subscription->setLinks(ResponseParser::links($result));
            $subscriptions[] = $subscription;
        }

        $collection = new SubscriptionCollection();
        $collection->subscriptions = $subscriptions;
        $collection->pagination = new Pagination(ResponseParser::pagination($results));

        return $collection;
    }

    /**
     * Get specific subscription
     * @param $subscriptionId
     * @return DetailedSubscription
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getSubscription($subscriptionId) {
        if (null === $subscriptionId || '' === $subscriptionId) {
            throw new ApiException('Subscription ID must be provided');
        }

        $result = $this->api->request('GET', 'subscription/' . rawurlencode($subscriptionId));
        $subscription = new DetailedSubscription(ResponseParser::data($result));
        $subscription->setLinks(ResponseParser::links($result));

        return $subscription;
    }

    /**
     * Cancel specific subscription
     * @param $subscriptionId
     * @return DetailedSubscription
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function cancelSubscription($subscriptionId) {
        if (null === $subscriptionId || '' === $subscriptionId) {
            throw new ApiException('Subscription ID must be provided');
        }

        $result = $this->api->request('POST', 'subscription/' . rawurlencode($subscriptionId) . '/cancel');
        $subscription = new DetailedSubscription(ResponseParser::data($result));
        $subscription->setLinks(ResponseParser::links($result));
        return $subscription;
    }

    /**
     * Create transaction from subscription
     * @param $uuid
     * @param int $amount
     * @param string $orderId
     * @param bool $surchargeEnabled
     * @param int $surchargeVatRate
     * @return DetailedTransaction
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function createTransactionFromSubscription($uuid, $amount, $orderId, $surchargeEnabled = false, $surchargeVatRate = 0) {
        if (null === $uuid || '' === $uuid) {
            throw new ApiException('Subscription UUID must be provided');
        }

        $json = [
            'data' => [
                'amount' => $amount,
                'order_id' => $orderId,
                'surcharge_enabled' => $surchargeEnabled,
                'surcharge_vat_rate' => $surchargeVatRate,
            ],
        ];

        $result = $this->api->request('POST', 'subscription/' . rawurlencode($uuid) . '/authorize', $json);

        $transaction = new DetailedTransaction(ResponseParser::data($result));
        $transaction->setLinks(ResponseParser::links($result));

        return $transaction;
    }

}
