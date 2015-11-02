<?php
/**
 * @package    LongCMS.Platform
 *
 * @copyright  Copyright (C) 2005 - 2012 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE
 */

defined('JPATH_PLATFORM') or die('Restricted access');

/**
 * LongCMS Platform Factory class
 *
 * @package  LongCMS.Platform
 * @since    11.1
 */
class GeoMerchantCartu
{
	protected $_db;
	protected $_date;
	protected $_app;
	protected $_user;
	protected $_mailer;
	protected $_type = 1;
	protected $_method;
	protected $_transaction;
	protected $_message;
	private $_extraData;
	private $_data = array(
		'PurchaseDesc'=>null,
		'PurchaseAmt'=>null,
		'CountryCode'=>268,
		'CurrencyCode'=>981,
		'MerchantName'=>'Site.ge!www.site.ge',
		'MerchantURL'=>'http://www.site.ge/index.php',
		'MerchantCity'=>'Tbilisi',
		'MerchantID'=>'000000008000812-00000001',
		'xDDDSProxy.Language'=>'01',
		);
	const URL = 'https://e-commerce.cartubank.ge/servlet/Process3DSServlet/3dsproxy_init.jsp';

	public function __construct()
	{
		$this->_db = JFactory::getDBO();
		$this->_date = JFactory::getDate();
		$this->_app = JFactory::getApplication();
		$this->_user = PDeals::getUser();
		$this->_mailer = JFactory::getMailer();

	}

	public function completePayment()
	{
		return true;
	}

	public function sendMails()
	{
		$transaction = $this->_transaction;


		PDeals::sendMailToAdmin($transaction);

		PDeals::sendMailToUser($transaction);

		PDeals::sendMailToCompany($transaction);



		return true;
	}

	public function checkPayment()
	{
		return true;
	}

	public function setTransaction($transaction)
	{
		$this->_transaction = $transaction;
	}

	public function getType()
	{
		return $this->_type;
	}

	public function getMessage()
	{
		return $this->_message;
	}


	protected function _setMessage($msg)
	{
		$this->_message = $msg;
	}


	public function getRedirectUrl()
	{
		$url = new JURI(self::URL);

		$this->_data['PurchaseDesc'] = $this->_transaction->getTransactionNumber();
		$this->_data['PurchaseAmt'] = $this->_transaction->getTotal();

		/*if (JDEBUG) {
			$this->_data['PurchaseAmt'] = '0.10';
		}*/

		foreach($this->_data as $k=>$v) {
			if ($k == 'MerchantURL') {
				//$v = rawurlencode($v);
			}
			$url->setVar($k, $v);
		}

		return (string)$url;
	}


	public function setExtraData($data)
	{
		$this->_extraData = $data;
	}

	public function checkPaymethodCert($ConfirmRequest, $signature)
	{
		if (JDEBUG) {
			return true;
		}

		if (empty($ConfirmRequest) || empty($signature)) {
			return false;
		}

		return true;

		$cert_file = JPATH_BASE.'/cli/ssl/CartuVerify.crt';
		$cert = file_get_contents($cert_file);
  		$key = openssl_get_publickey($cert);

		if (!openssl_verify('ConfirmRequest='.$ConfirmRequest,  base64_decode($signature), $key, OPENSSL_ALGO_SHA1)) {
			return false;
		}
		return true;
	}



	public function payMethodProcess()
	{
		$transaction_id = $this->_transaction->getTransactionId();
		$transaction_status = $this->_transaction->getStatus();
		//Transaction::log('transaction_status: '.$transaction_status, false, 'visa');


		if (!$transaction_id) {
			Transaction::log('VISA Error. Order not found!', true, 'visa');
			$this->displayPayMethodError('Order not found');
		}

		$deals = $this->_transaction->getDeals();
		if (empty($deals)) {
			Transaction::log('VISA Error. Order associated deals not found!', true, 'visa');
			$this->displayPayMethodError('Order associated deals not found');
		}

		$Status = $this->_extraData['Status'];
		switch($Status) {
			case 'C': // CHECK
				// check product availability, check user availability
				if ($transaction_status != 1) {
					Transaction::log('VISA Request C. Error. Order status not pending!', true, 'visa');
					$this->displayPayMethodError('Order status not pending');
				}


				Transaction::log('VISA Request C. Success.', true, 'visa');

				$this->displayPayMethodSuccess('Check success');


				break;

			case 'Y': // success
				// set transaction status to 2
				// increment product qty
				// save transaction extra data
				if ($transaction_status != 1) {
					Transaction::log('VISA Request Y. Error. Order status not pending!', true, 'visa');
					$this->displayPayMethodError('Order status not pending');
				}


				$query	 = $this->_db->getQuery(true);

				$this->_db->transactionStart();
				foreach($deals as $deal) {
					$deal_id = $deal->deal_id;

					$query_str = "UPDATE `#__deals_deals` "
									." SET sold=sold+1 "
									." WHERE id=".$deal_id." "
									."LIMIT 1 "
									;
					$this->_db->setQuery($query_str);
					$status = $this->_db->query();
					if (!$status) {
						$this->_db->transactionRollback();
						Transaction::log('VISA Request Y. Error! Cant update deal info with ID: '.$deal_id.'!', true, 'visa');
						$this->displayPayMethodError('Cant update deal info with ID: '.$deal_id);
					}
				}

				$status = $this->_transaction->updateStatus(2, json_encode($this->_extraData));
				if (!$status) {
					$this->_db->transactionRollback();
					Transaction::log('VISA Request Y. Error! Cant update order status.', true, 'visa');
					$this->displayPayMethodError('Cant update order status');
				}

				$this->_db->transactionCommit();
				Transaction::log('VISA Request Y. Success.', true, 'visa');


				// send success mails
				$this->sendMails();


				$this->displayPayMethodSuccess('Y success');
				break;

			case 'N': // failed
				// set transaction status to 0
				Transaction::log('VISA Request N.', true, 'visa');
				$this->_transaction->updateStatus(0, json_encode($this->_extraData));
				$this->displayPayMethodError('Transaction failed');
				break;

			case 'U': // unfinished

				if ($transaction_status != 1) {
					Transaction::log('VISA Request U. Error. Order status not pending!', true, 'visa');
					$this->displayPayMethodError('Order status not pending');
				}

				Transaction::log('VISA Request U.', true, 'visa');
				$this->displayPayMethodError('U request');
				break;
		}
		$this->displayPayMethodError('Request type not defined');
	}

	public function displayPayMethodError($reason = false)
	{
		JResponse::setHeader('Content-Type', 'text/xml');
		JResponse::sendHeaders();

		ob_start();
  		?>
		<ConfirmResponse>
			<TransactionId><?php echo $this->_extraData['TransactionId'] ?></TransactionId>
			<PaymentId><?php echo $this->_extraData['PaymentId'] ?></PaymentId>
			<Status>DECLINED</Status>
		</ConfirmResponse>
		<?php
		$xml = ob_get_clean();
		echo $xml;
		Transaction::log('VISA Response Error: '.$reason.'. Response Data: '.preg_replace('#\s+#', '', $xml), true, 'visa');
		jexit();
	}

	public function displayPayMethodSuccess($reason = false)
	{
 		JResponse::setHeader('Content-Type', 'text/xml');
		JResponse::sendHeaders();
		ob_start();
 		?>
		<ConfirmResponse>
			<TransactionId><?php echo $this->_extraData['TransactionId'] ?></TransactionId>
			<PaymentId><?php echo $this->_extraData['PaymentId'] ?></PaymentId>
			<Status>ACCEPTED</Status>
		</ConfirmResponse>
		<?php
		$xml = ob_get_clean();
		echo $xml;
		Transaction::log('VISA Response Success: '.$reason.'. Response Data: '.preg_replace('#\s+#', '', $xml), true, 'visa');
		jexit();
	}



}
