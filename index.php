<?php
//##copyright##

if (iaView::REQUEST_HTML == $iaView->getRequestType())
{
	iaBreadcrumb::remove(iaBreadcrumb::POSITION_LAST);
	$iaView->set('nocsrf', true);

	if (empty($_POST))
	{
		return iaView::errorPage(iaView::ERROR_NOT_FOUND);
	}

	$iaTransaction = $iaCore->factory('transaction');
	$iaPaypal = $iaCore->factoryPlugin('paypal', 'common');

	if (!$iaPaypal->checkIpnMessage())
	{
		$iaTransaction->addIpnLogEntry($iaPaypal->getPluginName(), $_POST, 'Invalid');

		return iaView::errorPage(iaView::ERROR_NOT_FOUND);
	}

	$iaTransaction->addIpnLogEntry($iaPaypal->getPluginName(), $_POST, 'Valid');

	$iaView->disableLayout();

	$iaPaypal->handleIpn($_POST);
}