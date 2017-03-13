<?php
/******************************************************************************
 *
 * Subrion - open source content management system
 * Copyright (C) 2017 Intelliants, LLC <https://intelliants.com>
 *
 * This file is part of Subrion.
 *
 * Subrion is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Subrion is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Subrion. If not, see <http://www.gnu.org/licenses/>.
 *
 *
 * @link https://subrion.org/
 *
 ******************************************************************************/

class iaPaypal extends abstractCore
{
	const API_VERSION = '109.0';

	const ENDPOINT_LIVE = 'https://api-3t.paypal.com/nvp';
	const ENDPOINT_SANDBOX = 'https://api-3t.sandbox.paypal.com/nvp';

	const URL_LIVE = 'https://www.paypal.com/cgi-bin/webscr';
	const URL_SANDBOX = 'https://www.sandbox.paypal.com/cgi-bin/webscr';

	const PAYMENT_TYPE_SALE = 'Sale';
	const PAYMENT_OPTION_RECURRING = 'RecurringPayments';

	const IPN_RESPONSE_VERIFIED = 'VERIFIED';

	const RESPONSE_SUCCESS = 'SUCCESS';
	const RESPONSE_SUCCESSWITHWARNING = 'SUCCESSWITHWARNING';

	const ITEM_CATEGORY_DIGITAL = 'Digital';
	const ITEM_CATEGORY_PHYSICAL = 'Physical';

	const METHOD_SET_EC = 'SetExpressCheckout';
	const METHOD_DO_EC = 'DoExpressCheckoutPayment';
	const METHOD_GET_EC_DETAILS = 'GetExpressCheckoutDetails';
	const METHOD_CREATE_RECURRING_PAYMENTS_PROFILE = 'CreateRecurringPaymentsProfile';
	const METHOD_REFUND_TRANSACTION = 'RefundTransaction';

	const REFUND_TYPE_FULL = 'Full';
	const REFUND_TYPE_PARTIAL = 'Partial';

	// not used
	const IPN_TXN_TYPE_SUBSCR_CANCEL = 'subscr_cancel'; // Subscription canceled
	const IPN_TXN_TYPE_SUBSCR_EOT = 'subscr_eot'; // Subscription expired
	const IPN_TXN_TYPE_SUBSCR_FAILED = 'subscr_failed'; // Subscription payment failed
	const IPN_TXN_TYPE_SUBSCR_MODIFIED = 'subscr_modify'; // Subscription modified
	const IPN_TXN_TYPE_SUBSCR_PAYMENT = 'subscr_payment'; // Subscription payment received
	const IPN_TXN_TYPE_SUBSCR_SIGNUP = 'subscr_signup'; // Subscription started
	//

	protected $_pluginName = 'paypal';

	protected $_token;
	protected $_response;

	// preset of sandbox credentials
	protected $_user = 'sdk-three_api1.sdk.com';
	protected $_password = 'QFZCWN5HZM8VBG7Q';
	protected $_signature = 'A-IzJhZZjhg29XQ2qnhapuwxIDzyAZQ92FRP5dqBzVesOkzbdUONzmOU';

	protected $_endpointUrl = self::ENDPOINT_SANDBOX;
	protected $_url = self::URL_SANDBOX;

	protected $_certPath;

	protected $_currencyCode = '';


	public function init()
	{
		parent::init();

		if (!$this->iaCore->get('paypal_demo_mode'))
		{
			$this->_endpointUrl = self::ENDPOINT_LIVE;
			$this->_url = self::URL_LIVE;

			$this->_user = $this->iaCore->get('paypal_api_user');
			$this->_password = $this->iaCore->get('paypal_api_password');
			$this->_signature = $this->iaCore->get('paypal_api_signature');
		}

		$this->_certPath = IA_MODULES . $this->getPluginName() . IA_URL_DELIMITER . 'includes/cert/g5-root.cer';
		$this->_currencyCode = $this->iaCore->get('paypal_currency_code');
	}

	public function getPluginName()
	{
		return $this->_pluginName;
	}

	public function redirect()
	{
		$url = $this->getUrl('cmd=_express-checkout&token=') . urlencode($this->_token);

		header('Location: ' . $url);
		exit;
	}

	public function getUrl($params = '')
	{
		return $this->_url . ($params ? '?' . $params : '');
	}

	public function getIpnUrl()
	{
		$iaPage = $this->iaCore->factory('page', iaCore::FRONT);

		return $iaPage->getUrlByName('ipn_paypal');
	}

	public function getError()
	{
		$code = urldecode($this->getResponse()->l_errorcode0);
		$message = urldecode($this->getResponse()->l_longmessage0);

		return 'Error ' . $code . ': ' . $message;
	}

	public function getToken()
	{
		return $this->getResponse()->token;
	}

	public function getResponse($asPlainArray = false)
	{
		if ($this->_response)
		{
			return $asPlainArray
				? $this->_response
				: (object)$this->_response;
		}

		return null;
	}

	protected function _apiCall($methodName, array $params = array())
	{
		$defaults = array_merge($params, array(
			'method' => $methodName,
			'version' => self::API_VERSION,
			'pwd' => $this->_password,
			'user' => $this->_user,
			'signature' => $this->_signature
		));

		$params = array_merge($defaults, $params);
		$params = http_build_query(array_change_key_case($params, CASE_UPPER));

		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, $this->_endpointUrl);
		curl_setopt($ch, CURLOPT_VERBOSE, true);

		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_CAINFO, $this->_certPath);

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $params);

		$response = curl_exec($ch);

		if (curl_errno($ch))
		{
			$result = false;
		}
		else
		{
			$this->_response = $this->_explodeNVP($response);

			$ack = strtoupper($this->getResponse()->ack);
			$result = (self::RESPONSE_SUCCESS == $ack || self::RESPONSE_SUCCESSWITHWARNING == $ack);
		}

		curl_close($ch);

		if ($result && isset($this->getResponse()->token))
		{
			$this->_token = $this->getResponse()->token;
		}

		return $response;
	}

	protected function _explodeNVP($nvp)
	{
		$intial = 0;
		$result = array();

		while (strlen($nvp))
		{
			$keypos = strpos($nvp,'=');
			$valuepos = strpos($nvp,'&') ? strpos($nvp,'&') : strlen($nvp);

			$keyval = substr($nvp, $intial, $keypos);
			$valval = substr($nvp, $keypos+1, $valuepos-$keypos-1);

			$result[strtolower(urldecode($keyval))] = urldecode($valval);
			$nvp = substr($nvp, $valuepos+1, strlen($nvp));
		}

		return $result;
	}

	protected function _getRawPostString()
	{
		$rawPost = explode('&', file_get_contents('php://input'));
		$post = array();

		foreach ($rawPost as $chunk)
		{
			$keyval = explode ('=', $chunk);
			if (count($keyval) == 2)
				$post[$keyval[0]] = urldecode($keyval[1]);
		}

		if (function_exists('get_magic_quotes_gpc') && get_magic_quotes_gpc())
		{
			array_map('stripslashes', $post);
		}

		$result = '';
		foreach ($post as $key => $value)
		{
			$result.= '&' . $key . '=' . urlencode($value);
		}

		return $result;
	}

	public function checkIpnMessage()
	{
		if (!($ch = curl_init($this->getUrl())))
		{
			return false;
		}

		$postData = 'cmd=_notify-validate' . $this->_getRawPostString();

		curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_CAINFO, $this->_certPath);

		curl_setopt($ch, CURLOPT_FORBID_REUSE, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Connection: Close'));

		$response = curl_exec($ch);

		if (curl_errno($ch))
		{
			curl_close($ch);

			return false;
		}
		else
		{
			curl_close($ch);
		}

		return (0 === strcmp($response, self::IPN_RESPONSE_VERIFIED));
	}


	/* API CALLS */
	public function setExpressCheckout(array $planInfo, $description, $returnURL, $cancelURL)
	{
		$params = array(
			'brandname' => $this->iaCore->get('site'),
			//'localecode' => $this->iaCore->iaView->language,
			'returnurl' => $returnURL,
			'cancelurl' => $cancelURL,
			'allownote' => 1,
			'paymentrequest_0_amt' => (float)$planInfo['cost'],
			'paymentrequest_0_paymentaction' => self::PAYMENT_TYPE_SALE,
			'paymentrequest_0_currencycode' => $this->_currencyCode,
			'paymentrequest_0_desc' => $description,
			'solutiontype' => 'Sole',
			'landingpage' => 'Billing',
			'localecode' => 'us'
		);

		if (iaUsers::hasIdentity())
		{
			$userInfo = iaUsers::getIdentity(true);

			$params['email'] = $userInfo['email'];
			//$params['buyerid'] = $userInfo['id'];
			//$params['buyerusername'] = $userInfo['fullname'];
		}

		if (isset($planInfo['recurring']) && $planInfo['recurring'])
		{
			$params['l_billingtype0'] = self::PAYMENT_OPTION_RECURRING;
			$params['l_billingagreementdescription0'] = $description;
		}

		return $this->_apiCall(self::METHOD_SET_EC, $params);
	}

	public function doExpressCheckoutPayment($token, $payer, $amount)
	{
		return $this->_apiCall(self::METHOD_DO_EC, array(
			'token' => $token,
			'payerid' => $payer,
			'paymentrequest_0_amt' => $amount,
			'paymentrequest_0_paymentaction' => self::PAYMENT_TYPE_SALE,
			'paymentrequest_0_currencycode' => $this->_currencyCode,
			//'paymentrequest_0_custom' => (int)iaUsers::getIdentity()->id,
			'notifyurl' => $this->getIpnUrl()
		));
	}

	public function getExpressCheckoutDetails($token)
	{
		return $this->_apiCall(self::METHOD_GET_EC_DETAILS, array('token' => $token));
	}

	public function refundTransaction($txnId)
	{
		return $this->_apiCall(self::METHOD_REFUND_TRANSACTION, array(
			'transactionid' => $txnId,
			'refundtype' => self::REFUND_TYPE_FULL
		));
	}

	public function createRecurringPaymentsProfile(array $planInfo, $description, $token, $payer)
	{
		$params = array(
			'token' => $token,
			'payerid' => $payer,
			'profilestartdate' => gmdate('Y-m-d\TH:i:s\Z'),
			'desc' => $description,
			'billingperiod' => ucfirst($planInfo['unit']),
			'billingfrequency' => $planInfo['duration'],
			'totalbillingcycles' => $planInfo['cycles'],
			'amt' => $planInfo['cost'],
			'currencycode' => $this->_currencyCode
		);

		if (iaUsers::hasIdentity())
		{
			$params['subscribername'] = iaUsers::getIdentity()->fullname;
			$params['email'] = iaUsers::getIdentity()->email;
		}

		return $this->_apiCall(self::METHOD_CREATE_RECURRING_PAYMENTS_PROFILE, $params);
	}
	//


	public function handleIpn(array $ipnData)
	{
		$iaSubscription = $this->iaCore->factory('subscription');

		switch ($ipnData['txn_type'])
		{
			case 'cart':
			case 'masspay':
			case 'express_checkout':
			case 'send_money':
			case 'recurring_payment':
			case 'recurring_payment_expired':
			case 'recurring_payment_failed':
			case 'recurring_payment_skipped':
				$this->_processIpnPayment($ipnData);

				break;

			case 'recurring_payment_profile_created':
				$values = array(
					'date_created' => date(iaDb::DATETIME_FORMAT, strtotime($ipnData['time_created'])),
					'date_next_payment' => date(iaDb::DATETIME_FORMAT, strtotime($ipnData['next_payment_date'])),
					'status' => iaSubscription::ACTIVE
				);

				$iaSubscription->update($values, $ipnData['recurring_payment_id']);

				break;

			case 'recurring_payment_suspended':
			case 'recurring_payment_suspended_due_to_max_failed_payment':
				$iaSubscription->update(array('status' => iaSubscription::SUSPENDED), $ipnData['recurring_payment_id']);

				break;

			case 'recurring_payment_profile_cancel':
				$iaSubscription->update(array('status' => iaSubscription::CANCELED), $ipnData['recurring_payment_id']);
		}
	}

	protected function _processIpnPayment(array $ipnData)
	{
		$checks = array(
			'receiver_email' => $this->iaCore->get('paypal_email'),
			'mc_currency' => $this->iaCore->get('paypal_currency_code')
		);

		$error = false;
		foreach ($checks as $key => $expectedValue)
		{
			if (empty($ipnData[$key]) || $ipnData[$key] != $expectedValue)
			{
				$error = true;
				break;
			}
		}

		if (!empty($ipnData['payment_gross']))
		{
			$amount = $ipnData['payment_gross'];
		}
		elseif (!empty($ipnData['mc_gross']))
		{
			$amount = $ipnData['mc_gross'];
		}
		else
		{
			$amount = $ipnData['amount3'];
		}

		if (!$error)
		{
			$transaction = array(
				'email' => $ipnData['payer_email'],
				'reference_id' => $ipnData['txn_id'],
				'amount' => $amount,
				'fullname' => $ipnData['first_name'] . ' ' . $ipnData['last_name'],
				'currency' => $ipnData['mc_currency'],
				'gateway' => $this->getPluginName(),
				'notes' => '<Automatically processed IPN transaction>',
				'demo' => isset($ipnData['test_ipn']) && 1 == $ipnData['test_ipn']
			);

			if (!empty($ipnData['recurring_payment_id']) || !empty($ipnData['subscr_id']))
			{
				$iaSubscription = $this->iaCore->factory('subscription');

				$referenceId = empty($ipnData['recurring_payment_id']) ? $ipnData['subscr_id'] : $ipnData['recurring_payment_id'];

				if ($subscription = $iaSubscription->getByReferenceId($referenceId))
				{
					$transaction['subscription_id'] = $subscription['id'];
					$transaction['plan_id'] = $subscription['plan_id'];
					$transaction['member_id'] = $subscription['member_id'];

					empty($subscription['item']) || $transaction['item'] = $subscription['item'];
					empty($subscription['item_id']) || $transaction['item_id'] = $subscription['item_id'];
				}
			}

			$iaTransaction = $this->iaCore->factory('transaction');

			switch ($ipnData['payment_status'])
			{
				case 'Completed':
				case 'Canceled_Reversal':
					$transaction['status'] = iaTransaction::PASSED;
					break;
				case 'Pending':
					$transaction['status'] = iaTransaction::PENDING;
					break;
				case 'Expired':
				case 'Failed':
				case 'Denied':
					$transaction['status'] = iaTransaction::FAILED;
					break;
				case 'Refunded':
				case 'Reversed':
					$transaction['status'] = iaTransaction::REFUNDED;
			}

			$txn = $iaTransaction->getBy('reference_id', $transaction['reference_id']);
			$txn ? $iaTransaction->update($transaction, $txn['id']) : $iaTransaction->createIpn($transaction);

			$this->_sendEmailNotification($transaction);
		}
	}

	protected function _sendEmailNotification(array $transaction)
	{
		$emailTemplate = 'paypal_ipn_admin';

		if (!$this->iaCore->get($emailTemplate))
		{
			return true;
		}

		$iaMailer = $this->iaCore->factory('mailer');

		$iaMailer->loadTemplate($emailTemplate);
		$iaMailer->setReplacements($transaction);

		return $iaMailer->sendToAdministrators();
	}

	public function refund(array $transaction)
	{
		if (empty($transaction['reference_id']))
		{
			return false;
		}

		if (!$this->refundTransaction($transaction['reference_id']))
		{
			return false;
		}

		return $this->getResponse(true);
	}
}