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

            $login = array_rand($this->activeWallets); // списываем со случайного валета из пула?
            $result->setAccount($login);

            $api = $this->getWalletApi($login);

            $wallet = $api->normalizePhoneOrFail($wallet);
            $amount = $api->normalizeAmountOrFail($amount); // считаем, что amount проверен и приведен к нужному типу/виду?
            $comment = trim(@(string)$comment);

            $balance = $api->getBalance();
            if ($amount > $balance - $this->balanceMin) { // если в несколько потоков, то можем уйти ниже balanceMin без всяких эксепшенов (если для разных потоков не использовать различные wallet-ы или единую систему контроля баланса)
                throw new LE('Insufficient funds.');
            }

            try {
                $transferResult = $api->transfer($wallet, $amount, $comment);
            } catch (QiwiUncertaintyException $e) {
                $result->setSuccess(); // почему success?
                throw $e; // тут все должно упасть т.к. ниже ловится Exception, а дерево исключений, похоже, следующее QiwiUncertaintyException <- QiwiException <- \Exception
            }

            $result->setExternalId($transferResult->getId());

            $transaction = $api->getTransaction($transferResult->getId(), QiwiWalletApi::TXN_TYPE_OUT);
            $result->setFee($transaction->getCommission());

            $result->setSuccess();
        } catch (Exception $e) { // если будет выкинут эксепшн унаследованный не от Exception - падаем (у QiwiWalletApi свои эксепшены)
            // при QiwiUncertaintyException, похоже, сюда не попадем т.к. QiwiUncertaintyException <- QiwiException <- \Exception
            // но судя по index.php/13 мы, вроде как, хотим именно сюда - установить setSuccess и setFailure
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
            } catch (Exception $e) { // опять же эксепшены Qiwi не ловим, ловим только наследованные от Exception
                $balance = null;
            }
            $balances[$login] = $balance;
        }

        return $balances;
    }
}