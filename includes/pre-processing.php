<?php
//##copyright##

$iaPaypal = $iaCore->factoryPlugin('paypal', 'common');

$returnURL = IA_RETURN_URL . 'completed/';
$cancelURL = IA_RETURN_URL . 'canceled/';

$iaPaypal->setExpressCheckout($plan, $transaction['operation'], $returnURL, $cancelURL)
	? $iaPaypal->redirect()
	: $iaView->setMessages($iaPaypal->getError());