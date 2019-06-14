<?php

use Payment\ExtCurrency;
use PaymentSystems\QiwiPaymentSystem;
use Withdraw\WithdrawalResult;

require_once 'vendor/autoload.php';

define('EPSILON', 0.0000001);

function getResultState(WithdrawalResult $result): string
{
    if ($result->isSuccess() && $result->isFailure()) {
        return 'Под вопросом';
    }
    if ($result->isSuccess()) {
        return 'Успешно';
    }
    if ($result->isFailure()) {
        return 'Ошибка';
    }

    return 'Пусто';
}

$wallets = [
    '+79161111111' => ['password' => '111'],
    '+79162222222' => ['password' => '222'],
];

$tasks = [
    1 => ['+79161112233', 1000],
    ['+79034445566', 50.95],
    ['+79267778899', 78],
];

$qiwi = new QiwiPaymentSystem();
$qiwi->setWallets($wallets);
$extCy = new ExtCurrency('qiwi', 'QIWI', '₽', 'Кошелек');
?>

<table border="1">
    <thead>
    <tr>
        <th>#</th>
        <th>получатель</th>
        <th>сумма</th>
        <th>статус</th>
        <th>описание</th>
        <th>отправитель</th>
        <th>транзакция</th>
        <th>комиссия</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($tasks as $taskId => [$wallet, $sum]): ?>
        <?php
        $result = $qiwi->withdraw($extCy, $wallet, $sum, 'Test', $taskId);
        ?>
        <tr>
            <td><?= e($taskId) ?></td>
            <td><?= e($wallet) ?></td>
            <td><?= e($sum) ?></td>
            <td><?= e(getResultState($result)) ?></td>
            <td><?= $result->isFailure() ? e($result->getFailureReason()) : '' ?></td>
            <td><?= e($result->getAccount() ?: '?') ?></td>
            <td><?= e($result->getExternalId() ?: '?') ?></td>
            <td><?= e($result->getFee() ?? '?') ?></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
