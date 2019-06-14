<?php

namespace PaymentSystems;

use Core;
use Exception;
use LE;
use Payment\ExtCurrency;
use Payment\QiwiWalletApi\QiwiUncertaintyException;
use Payment\QiwiWalletApi\QiwiWalletApi;
use PaymentSystem;
use Withdraw\WithdrawalResult;

class QiwiPaymentSystem extends PaymentSystem
{
    private $shopId = 0;
    private $balanceMin = 0;
    private $activeWallets = [];
    /**
     * @var QiwiWalletApi[]
     */
    private $apiInstances = [];

    public function setWallets(array $wallets)
    {
        $this->activeWallets = $wallets;
        $this->output = (bool)$wallets;
    }

    public function isPhoneRequired()
    {
        return true;
    }

    public function getInputAccount()
    {
        $this->requireActiveInput();

        return $this->shopId;
    }

    private function getWalletApi($login): QiwiWalletApi
    {
        if (!isset($this->apiInstances[$login])) {
            $wallet = $this->activeWallets[$login] ?? null;
            if (!$wallet) {
                throw new LE('Unknown wallet.');
            }

            $api = new QiwiWalletApi();
            $api->setToken($wallet['password']);

            $proxy = getArrayFromArray(Core::$config, 'proxy');
            if (!empty($proxy['host'])) {
                $api->setProxy($proxy['host'], $proxy['port'], CURLPROXY_SOCKS5);
            }

            if ($api->getPhone() != $login) {
                throw new LE('Foreign token.');
            }

            $this->apiInstances[$login] = $api;
        }

        return $this->apiInstances[$login];
    }

    public function withdraw(ExtCurrency $extCurrency, string $wallet, $amount, $comment, $withdrawalId): WithdrawalResult
    {
        $result = new WithdrawalResult();

        try {
            $this->requireActiveOutput();

            $login = array_rand($this->activeWallets);
            $result->setAccount($login);

            $api = $this->getWalletApi($login);

            $wallet = $api->normalizePhoneOrFail($wallet);
            $amount = $api->normalizeAmountOrFail($amount);
            $comment = trim(@(string)$comment);

            $balance = $api->getBalance();
            if ($amount > $balance - $this->balanceMin) {
                throw new LE('Insufficient funds.');
            }

            try {
                $transferResult = $api->transfer($wallet, $amount, $comment);
            } catch (QiwiUncertaintyException $e) {
                $result->setSuccess();
                throw $e;
            }

            $result->setExternalId($transferResult->getId());

            $transaction = $api->getTransaction($transferResult->getId(), QiwiWalletApi::TXN_TYPE_OUT);
            $result->setFee($transaction->getCommission());

            $result->setSuccess();
        } catch (Exception $e) {
            $result->setFailure($e->getMessage(), $e);
        }

        return $result;
    }

    public function canGetBalances(): bool
    {
        return true;
    }

    public function getBalances(): array
    {
        $balances = [];
        foreach ($this->activeWallets as $login => $wallet) {
            try {
                $balance = $this->getWalletApi($login)->getBalance();
            } catch (Exception $e) {
                $balance = null;
            }
            $balances[$login] = $balance;
        }

        return $balances;
    }
}