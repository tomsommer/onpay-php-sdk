<?php

declare(strict_types=1);

namespace OnPay\API;

use OnPay\API\Exception\ApiException;
use OnPay\API\Transaction\DetailedTransaction;
use OnPay\API\Transaction\SimpleTransaction;
use OnPay\API\Transaction\TransactionCollection;
use OnPay\API\Transaction\TransactionEvent;
use OnPay\API\Transaction\TransactionEventCollection;
use OnPay\API\Util\Pagination;
use OnPay\Http\ApiClientInterface;
use OnPay\API\Util\ResponseParser;

class TransactionService {

    private $api;

    /**
     * @internal Should never be called outside the library
     * TransactionService constructor.
     * @param ApiClientInterface $onPayAPI
     */
    public function __construct(ApiClientInterface $onPayAPI) {
        $this->api = $onPayAPI;
    }

    /**
     * @param string $identifier
     * @return DetailedTransaction
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getTransaction($identifier) {
        if ('' === $identifier) {
            throw new ApiException('Transaction number must be provided');
        }
        $result = $this->api->request('GET', 'transaction/' . rawurlencode($identifier));

        $detailedTransaction = new DetailedTransaction(ResponseParser::data($result));
        $detailedTransaction->setLinks(ResponseParser::links($result));
        return $detailedTransaction;
    }

    /**
     * @param null $page
     * @param null $pageSize
     * @param null $orderBy
     * @param null $query
     * @param null $status
     * @param null $dateAfter
     * @param null $dateBefore
     * @param string $direction
     * @return TransactionCollection
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getTransactions($page = null, $pageSize = null, $orderBy = null, $query = null, $status = null, $dateAfter = null, $dateBefore = null, $direction = 'DESC') {
        $direction = strtoupper($direction);
        if ($direction !== 'ASC') {
            $direction = 'DESC';
        }
        $queryString = http_build_query(['page' => $page, 'page_size' => $pageSize, 'order_by' => $orderBy, 'query' => $query, 'status' => $status, 'date_after' => $dateAfter, 'date_before' => $dateBefore, 'direction' => $direction]);
        $results = $this->api->request('GET', 'transaction/?' . $queryString);

        $transactions = [];

        foreach (ResponseParser::collection($results) as $result) {
            $transaction = new SimpleTransaction($result);
            $transaction->setLinks(ResponseParser::links($result));
            $transactions[] = $transaction;
        }

        $collection = new TransactionCollection();
        $collection->transactions = $transactions;
        $collection->pagination = new Pagination(ResponseParser::pagination($results));

        return $collection;
    }

    /**
     * Perform Capture of transaction.
     * 
     * $amount and $postActionChargeAmount are mutually exclusive and can not both be used together
     * 
     * Using $amount, the transaction will have the supplied value captured.
     * Using $postActionChargeAmount, this value represents the charged value expected on the transaction after this action has completed. When this value is present the amount captured on the transaction will be automatically calculated to ensure this value is honoured.
     * 
     * If none of the amount parameters are supplied, the entire available amount will be captured.
     * 
     * @param string $transactionNumber
     * @param int|null $amount
     * @param int|null $postActionChargeAmount
     * @return DetailedTransaction
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function captureTransaction($transactionNumber, $amount = null, $postActionChargeAmount = null) {
        $jsonBody = null;
        if ('' === $transactionNumber) {
            throw new ApiException('Transaction number must be provided');
        }

        if(null !== $amount && null !== $postActionChargeAmount) {
            // Both amount parameters not allowed at the same time
            throw new ApiException('$amount and $postActionChargeAmount are mutually exclusive and can not both be used together');
        } else if (null !== $amount) {
            // Amount parameter supplied, add to json body
            $jsonBody = [
                'data' => [
                    'amount' => (int) $amount
                ]
            ];
        } else if (null !== $postActionChargeAmount) {
            // PostActionCaptureAmount parameter supplied, add to json body
            $jsonBody = [
                'data' => [
                    'postActionChargeAmount' => (int) $postActionChargeAmount
                ]
            ];
            
        }

        $result = $this->api->request('POST', 'transaction/' . rawurlencode($transactionNumber) . '/capture', $jsonBody);
        $transaction = new DetailedTransaction(ResponseParser::data($result));
        $transaction->setLinks(ResponseParser::links($result));

        return $transaction;
    }

    /**
     * @param string $transactionNumber
     * @return DetailedTransaction
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function cancelTransaction($transactionNumber) {
        if ('' === $transactionNumber) {
            throw new ApiException('Transaction number must be provided');
        }
        $result = $this->api->request('POST', 'transaction/' . rawurlencode($transactionNumber) . '/cancel');
        $transaction = new DetailedTransaction(ResponseParser::data($result));
        $transaction->setLinks(ResponseParser::links($result));
        return $transaction;
    }

    /**
     * Perform refund of transaction.
     * 
     * $amount and $postActionRefundAmount are mutually exclusive and can not both be used together
     * 
     * Using $amount, the transaction will have the supplied value refunded.
     * Using $postActionRefundAmount, this value represents the refunded value expected on the transaction after this action has completed. When this value is present the amount refunded on the transaction will be automatically calculated to ensure this value is honoured.
     * 
     * If none of the amount parameters are supplied, the entire available amount will be refunded.
     * 
     * @param string $transactionNumber
     * @param int|null $amount
     * @param int|null $postActionRefundAmount
     * @return DetailedTransaction
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function refundTransaction($transactionNumber, $amount = null, $postActionRefundAmount = null) {
        $jsonBody = null;
        
        if ('' === $transactionNumber) {
            throw new ApiException('Transaction number must be provided');
        }
        if(null !== $amount && null !== $postActionRefundAmount) {
            // Both amount parameters not allowed at the same time
            throw new ApiException('$amount and $postActionRefundAmount are mutually exclusive and can not both be used together');
        } else if (null !== $amount) {
            // Amount parameter supplied, add to json body
            $jsonBody = [
                'data' => [
                    'amount' => (int) $amount
                ]
            ];
        } else if (null !== $postActionRefundAmount) {
            // PostActionRefundAmount parameter supplied, add to json body
            $jsonBody = [
                'data' => [
                    'postActionRefundAmount' => (int) $postActionRefundAmount
                ]
            ];
        }

        $result = $this->api->request('POST', 'transaction/' . rawurlencode($transactionNumber) . '/refund', $jsonBody);
        $transaction = new DetailedTransaction(ResponseParser::data($result));
        $transaction->setLinks(ResponseParser::links($result));

        return $transaction;
    }

    /**
     * Every event on the gateway's transactions, oldest first.
     *
     * Paged by cursor rather than page number: keep the cursor from the last
     * page and pass it back to carry on. Originally implemented by Dennis
     * Vaeversted in 2020 on a branch that was never merged upstream.
     *
     * @param string|null $cursor The cursor from a previous call, or null to start
     * @throws Exception\ApiException
     * @throws Exception\ConnectionException
     * @throws Exception\TokenException
     */
    public function getEvents(?string $cursor = null): TransactionEventCollection {
        $url = 'transaction/events/';
        if (null !== $cursor && '' !== $cursor) {
            $url .= '?' . http_build_query(['cursor' => $cursor]);
        }

        $result = $this->api->request('GET', $url);

        $collection = new TransactionEventCollection();
        foreach (ResponseParser::collection($result) as $item) {
            $collection->events[] = new TransactionEvent($item);
        }

        $nextCursor = is_array($result) ? ($result['meta']['next_cursor'] ?? null) : null;
        $collection->nextCursor = (is_string($nextCursor) && '' !== $nextCursor) ? $nextCursor : null;

        return $collection;
    }
}
