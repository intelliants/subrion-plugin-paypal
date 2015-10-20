<?php
//##copyright##

if (empty($_GET['token']) || empty($_GET['PayerID']))
{
	$iaView->setMessages(iaLanguage::get('invalid_parameters'), iaView::ERROR);
	return;
}

$iaPaypal = $iaCore->factoryPlugin('paypal', 'common');
$iaSubscription = $iaCore->factory('subscription');

$token = $_GET['token'];
$payer = $_GET['PayerID'];
$plan = $iaPlan->getById($temp_transaction['plan_id']);

if ($plan['recurring'])
{
	$subscription = $iaSubscription->create($plan['id']);
	if ($iaPaypal->getExpressCheckoutDetails($token))
	{
		$checkoutDetails = $iaPaypal->getResponse();
		if ($iaPaypal->createRecurringPaymentsProfile($plan, $temp_transaction['operation'], $token, $payer))
		{
			$iaSubscription->activate($subscription, $iaPaypal->getResponse()->profileid);

			$iaView->setMessages(iaLanguage::get('recurring_payment_profile_added'), iaView::SUCCESS);
			iaUtil::go_to($temp_transaction['return_url']);
		}
		else
		{
			$iaView->setMessages(iaLanguage::get('could_not_create_recurring_profile'));
		}
	}
	else
	{
		$iaView->setMessages(iaLanguage::get('could_not_get_checkout_details'));
	}
}
else
{
	if ($iaPaypal->doExpressCheckoutPayment($token, $payer, $temp_transaction['amount']))
	{
		$iaPaypal->getExpressCheckoutDetails($token);
		$response = $iaPaypal->getResponse();

		$transaction = $temp_transaction;

		$transaction['date'] = date(iaDb::DATETIME_FORMAT, strtotime($response->timestamp));
		$transaction['reference_id'] = $response->paymentrequest_0_transactionid;
		$transaction['amount'] = $response->paymentrequest_0_amt;
		$transaction['currency'] = $response->paymentrequest_0_currencycode;
		$transaction['email'] = $response->email;
		$transaction['fullname'] = $response->firstname . ' ' . $response->lastname;
		$transaction['notes'] = $response->paymentrequest_0_notetext;

		switch ($response->checkoutstatus)
		{
			case 'PaymentActionCompleted':
				$transaction['status'] = iaTransaction::PASSED;
				break;
			case 'PaymentActionFailed':
			case 'PaymentActionNotInitiated':
				$transaction['status'] = iaTransaction::FAILED;
				break;
			case 'PaymentActionInProgress':
				$transaction['status'] = iaTransaction::PENDING;
		}

		$order['txn_id'] = $transaction['reference_id'];
		$order['payment_status'] = iaLanguage::get($transaction['status'], ucfirst($transaction['status']));
		$order['payer_email'] = $transaction['email'];
		$order['payment_gross'] = $transaction['amount'];
		$order['payment_date'] = $transaction['date'];
		$order['mc_currency'] = $transaction['currency'];
		$order['first_name'] = $response->firstname;
		$order['last_name'] = $response->lastname;

		$iaView->setMessages(iaLanguage::get('payment_completed'), iaView::SUCCESS);
	}
	else
	{
		$iaView->setMessages($iaPaypal->getError());
	}
}