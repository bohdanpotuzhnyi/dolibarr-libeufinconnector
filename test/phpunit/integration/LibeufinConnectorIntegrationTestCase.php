<?php
declare(strict_types=1);
/* Copyright (C) 2026       Bohdan Potuzhnyi
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

global $conf, $user, $db, $langs;

require_once dirname(__FILE__, 7).'/htdocs/master.inc.php';
require_once dirname(__FILE__, 7).'/test/phpunit/CommonClassTest.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/libeufinconnector/core/modules/modLibEuFinConnector.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/libeufinconnector/class/libeufintransaction.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/libeufinconnector/lib/transactionworkflow.lib.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/paiementfourn.class.php';

/**
 * Shared Dolibarr-backed fixture helpers for LibEuFin connector integration tests.
 *
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
abstract class LibeufinConnectorIntegrationTestCase extends CommonClassTest
{
	protected static ?DoliDB $dbHandle = null;
	protected static ?User $testUser = null;
	protected static int $bankAccountId = 0;
	protected static int $paymentModeId = 0;
	protected static int $customerSocid = 0;
	protected static int $supplierSocid = 0;
	protected static string $receivingIban = 'CH3189144456452697989';
	protected static string $receivingBic = 'POFICHBEXXX';
	protected static string $counterpartyIban = 'CH5604835012345678009';
	protected static string $counterpartyBic = 'POFICHBEXXX';

	/**
	 * Bootstrap Dolibarr, enable the module, and cache common fixture ids.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		global $db, $user, $conf;

		self::$dbHandle = $db;
		self::$testUser = $user;

		$module = new modLibEuFinConnector($db);
		$module->init('');

		self::$bankAccountId = self::fetchFirstInt(
			"SELECT rowid FROM ".MAIN_DB_PREFIX."bank_account ORDER BY rowid ASC LIMIT 1"
		);
		self::$paymentModeId = (int) dol_getIdFromCode($db, 'VIR', 'c_paiement', 'code', 'id', 1);
		self::$customerSocid = self::fetchFirstInt(
			"SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE client IN (1,2,3) ORDER BY rowid ASC LIMIT 1"
		);
		self::$supplierSocid = self::fetchFirstInt(
			"SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE fournisseur = 1 ORDER BY rowid ASC LIMIT 1"
		);

		self::assertGreaterThan(0, self::$bankAccountId, 'A bank account fixture is required.');
		self::assertGreaterThan(0, self::$paymentModeId, 'The VIR payment mode is required.');
		self::assertGreaterThan(0, self::$customerSocid, 'A customer fixture is required.');
		self::assertGreaterThan(0, self::$supplierSocid, 'A supplier fixture is required.');

		self::ensureReceivingBankAccountFixture();
		self::ensureThirdpartyBankAccountFixture(self::$customerSocid, 'LibEuFin Customer Counterparty');
		if (self::$supplierSocid !== self::$customerSocid) {
			self::ensureThirdpartyBankAccountFixture(self::$supplierSocid, 'LibEuFin Supplier Counterparty');
		}

		dolibarr_set_const($db, 'LIBEUFINCONNECTOR_BANK_ACCOUNT_ID', (string) self::$bankAccountId, 'chaine', 0, '', $conf->entity);
		$conf->global->LIBEUFINCONNECTOR_BANK_ACCOUNT_ID = (string) self::$bankAccountId;
	}

	/**
	 * Fetch the first integer from a SQL statement.
	 *
	 * @param string $sql SQL query.
	 * @return int
	 */
	protected static function fetchFirstInt(string $sql): int
	{
		$resql = self::$dbHandle->query($sql);
		if (!$resql) {
			return 0;
		}

		$row = self::$dbHandle->fetch_row($resql);
		self::$dbHandle->free($resql);
		if (!is_array($row) || !isset($row[0])) {
			return 0;
		}

		return (int) $row[0];
	}

	/**
	 * Ensure the selected Dolibarr receiving bank account has deterministic banking data for tests.
	 *
	 * @return void
	 */
	protected static function ensureReceivingBankAccountFixture(): void
	{
		global $conf;

		$sql = "UPDATE ".MAIN_DB_PREFIX."bank_account";
		$sql .= " SET iban_prefix = '".self::$dbHandle->escape(self::$receivingIban)."'";
		$sql .= ", bic = '".self::$dbHandle->escape(self::$receivingBic)."'";
		if (!empty($conf->currency)) {
			$sql .= ", currency_code = '".self::$dbHandle->escape((string) $conf->currency)."'";
		}
		$sql .= " WHERE rowid = ".((int) self::$bankAccountId);

		$resql = self::$dbHandle->query($sql);
		self::assertNotFalse($resql, 'Test receiving bank account fixture update should succeed.');
	}

	/**
	 * Ensure a third party has a deterministic default bank account for outgoing staging tests.
	 *
	 * @param int    $socid Third-party id.
	 * @param string $label Fixture label prefix.
	 * @return void
	 */
	protected static function ensureThirdpartyBankAccountFixture(int $socid, string $label): void
	{
		global $conf;

		$thirdpartyName = '';
		$now = self::$dbHandle->idate(dol_now());
		$sql = "SELECT nom";
		$sql .= " FROM ".MAIN_DB_PREFIX."societe";
		$sql .= " WHERE rowid = ".((int) $socid);
		$sql .= " AND entity IN (".getEntity('societe').")";
		$sql .= " LIMIT 1";
		$resql = self::$dbHandle->query($sql);
		if ($resql) {
			$obj = self::$dbHandle->fetch_object($resql);
			if ($obj && isset($obj->nom)) {
				$thirdpartyName = trim((string) $obj->nom);
			}
			self::$dbHandle->free($resql);
		}
		if ($thirdpartyName === '') {
			$thirdpartyName = $label;
		}

		$bankAccountId = self::fetchFirstInt(
			"SELECT rowid FROM ".MAIN_DB_PREFIX."societe_rib WHERE fk_soc = ".((int) $socid)." AND type = 'ban' ORDER BY default_rib DESC, rowid ASC LIMIT 1"
		);

		$resetSql = "UPDATE ".MAIN_DB_PREFIX."societe_rib";
		$resetSql .= " SET default_rib = 0";
		$resetSql .= " WHERE fk_soc = ".((int) $socid);
		$resetSql .= " AND type = 'ban'";
		$resetResult = self::$dbHandle->query($resetSql);
		self::assertNotFalse($resetResult, 'Third-party bank account reset should succeed.');

		if ($bankAccountId > 0) {
			$updateSql = "UPDATE ".MAIN_DB_PREFIX."societe_rib";
			$updateSql .= " SET entity = ".((int) $conf->entity);
			$updateSql .= ", label = '".self::$dbHandle->escape($label)."'";
			$updateSql .= ", proprio = '".self::$dbHandle->escape($thirdpartyName)."'";
			$updateSql .= ", bic = '".self::$dbHandle->escape(self::$counterpartyBic)."'";
			$updateSql .= ", iban_prefix = '".self::$dbHandle->escape(self::$counterpartyIban)."'";
			$updateSql .= ", number = '".self::$dbHandle->escape(self::$counterpartyIban)."'";
			$updateSql .= ", default_rib = 1";
			$updateSql .= ", status = 1";
			if (!empty($conf->currency)) {
				$updateSql .= ", currency_code = '".self::$dbHandle->escape((string) $conf->currency)."'";
			}
			$updateSql .= " WHERE rowid = ".((int) $bankAccountId);

			$updateResult = self::$dbHandle->query($updateSql);
			self::assertNotFalse($updateResult, 'Third-party bank account fixture update should succeed.');

			return;
		}

		$insertSql = "INSERT INTO ".MAIN_DB_PREFIX."societe_rib (";
		$insertSql .= "entity, type, label, fk_soc, datec, number, bic, iban_prefix, proprio, default_rib, currency_code, status";
		$insertSql .= ") VALUES (";
		$insertSql .= ((int) $conf->entity);
		$insertSql .= ", 'ban'";
		$insertSql .= ", '".self::$dbHandle->escape($label)."'";
		$insertSql .= ", ".((int) $socid);
		$insertSql .= ", '".$now."'";
		$insertSql .= ", '".self::$dbHandle->escape(self::$counterpartyIban)."'";
		$insertSql .= ", '".self::$dbHandle->escape(self::$counterpartyBic)."'";
		$insertSql .= ", '".self::$dbHandle->escape(self::$counterpartyIban)."'";
		$insertSql .= ", '".self::$dbHandle->escape($thirdpartyName)."'";
		$insertSql .= ", 1";
		$insertSql .= ", '".self::$dbHandle->escape(!empty($conf->currency) ? (string) $conf->currency : 'EUR')."'";
		$insertSql .= ", 1";
		$insertSql .= ")";

		$insertResult = self::$dbHandle->query($insertSql);
		self::assertNotFalse($insertResult, 'Third-party bank account fixture insert should succeed.');
	}

	/**
	 * Create and validate a standard customer invoice.
	 *
	 * @param float $amount Invoice amount excluding tax.
	 * @return Facture
	 */
	protected function createValidatedCustomerInvoice(float $amount): Facture
	{
		$invoice = new Facture(self::$dbHandle);
		$invoice->socid = self::$customerSocid;
		$invoice->type = Facture::TYPE_STANDARD;
		$invoice->date = dol_now();
		$invoice->fk_account = self::$bankAccountId;

		$createResult = $invoice->create(self::$testUser);
		$this->assertGreaterThan(0, $createResult, 'Customer invoice creation should succeed.');

		$lineResult = $invoice->addline('LibEuFin customer invoice fixture', $amount, 1, 0, 0, 0, 0);
		$this->assertGreaterThan(0, $lineResult, 'Customer invoice line insertion should succeed.');

		$invoice->fetch((int) $invoice->id);
		$invoice->update_price(1);
		$validateResult = $invoice->validate(self::$testUser);
		$this->assertGreaterThan(0, $validateResult, 'Customer invoice validation should succeed.');
		$invoice->fetch((int) $invoice->id);

		return $invoice;
	}

	/**
	 * Create and validate a customer credit note.
	 *
	 * @param float $amount Credit-note amount excluding tax.
	 * @return Facture
	 */
	protected function createValidatedCustomerCreditNote(float $amount): Facture
	{
		$invoice = new Facture(self::$dbHandle);
		$invoice->socid = self::$customerSocid;
		$invoice->type = Facture::TYPE_CREDIT_NOTE;
		$invoice->date = dol_now();
		$invoice->fk_account = self::$bankAccountId;

		$createResult = $invoice->create(self::$testUser);
		$this->assertGreaterThan(0, $createResult, 'Customer credit note creation should succeed.');

		$lineResult = $invoice->addline('LibEuFin customer credit note fixture', $amount, 1, 0, 0, 0, 0);
		$this->assertGreaterThan(0, $lineResult, 'Customer credit note line insertion should succeed.');

		$invoice->fetch((int) $invoice->id);
		$invoice->update_price(1);
		$validateResult = $invoice->validate(self::$testUser);
		$this->assertGreaterThan(0, $validateResult, 'Customer credit note validation should succeed.');
		$invoice->fetch((int) $invoice->id);

		return $invoice;
	}

	/**
	 * Create and validate a standard supplier invoice.
	 *
	 * @param float $amount Invoice amount excluding tax.
	 * @return FactureFournisseur
	 */
	protected function createValidatedSupplierInvoice(float $amount): FactureFournisseur
	{
		$invoice = new FactureFournisseur(self::$dbHandle);
		$invoice->socid = self::$supplierSocid;
		$invoice->type = FactureFournisseur::TYPE_STANDARD;
		$invoice->date = dol_now();
		$invoice->datef = dol_now();
		$invoice->fk_account = self::$bankAccountId;
		$invoice->ref_supplier = 'LFC-SUP-'.uniqid();

		$createResult = $invoice->create(self::$testUser);
		$this->assertGreaterThan(0, $createResult, 'Supplier invoice creation should succeed.');

		$lineResult = $invoice->addline('LibEuFin supplier invoice fixture', $amount, 0, 0, 0, 1);
		$this->assertGreaterThan(0, $lineResult, 'Supplier invoice line insertion should succeed.');

		$invoice->fetch((int) $invoice->id);
		$invoice->update_price(1);
		$validateResult = $invoice->validate(self::$testUser);
		$this->assertGreaterThan(0, $validateResult, 'Supplier invoice validation should succeed.');
		$invoice->fetch((int) $invoice->id);

		return $invoice;
	}

	/**
	 * Create and validate a supplier credit note.
	 *
	 * @param float $amount Credit-note amount excluding tax.
	 * @return FactureFournisseur
	 */
	protected function createValidatedSupplierCreditNote(float $amount): FactureFournisseur
	{
		$invoice = new FactureFournisseur(self::$dbHandle);
		$invoice->socid = self::$supplierSocid;
		$invoice->type = FactureFournisseur::TYPE_CREDIT_NOTE;
		$invoice->date = dol_now();
		$invoice->datef = dol_now();
		$invoice->fk_account = self::$bankAccountId;
		$invoice->ref_supplier = 'LFC-SUP-CN-'.uniqid();

		$createResult = $invoice->create(self::$testUser);
		$this->assertGreaterThan(0, $createResult, 'Supplier credit note creation should succeed.');

		$lineResult = $invoice->addline('LibEuFin supplier credit note fixture', $amount, 0, 0, 0, 1);
		$this->assertGreaterThan(0, $lineResult, 'Supplier credit note line insertion should succeed.');

		$invoice->fetch((int) $invoice->id);
		$invoice->update_price(1);
		$validateResult = $invoice->validate(self::$testUser);
		$this->assertGreaterThan(0, $validateResult, 'Supplier credit note validation should succeed.');
		$invoice->fetch((int) $invoice->id);

		return $invoice;
	}

	/**
	 * Create a native customer refund payment for a customer credit note.
	 *
	 * @param Facture $creditNote Customer credit note.
	 * @param float   $amount Refund amount.
	 * @return Paiement
	 */
	protected function createCustomerRefundPayment(Facture $creditNote, float $amount): Paiement
	{
		$thirdparty = new Societe(self::$dbHandle);
		$thirdparty->fetch((int) $creditNote->socid);

		$payment = new Paiement(self::$dbHandle);
		$payment->datepaye = dol_now();
		$payment->amounts = array((int) $creditNote->id => -abs($amount));
		$payment->paiementid = self::$paymentModeId;
		$payment->paiementcode = 'VIR';
		$payment->num_payment = 'LFC-CPAY-'.uniqid();
		$payment->note_private = 'LibEuFin customer refund fixture';
		$payment->fk_account = self::$bankAccountId;

		$paymentId = $payment->create(self::$testUser, 1, $thirdparty);
		$this->assertGreaterThan(0, $paymentId, 'Customer refund payment creation should succeed.');

		$bankId = $payment->addPaymentToBank(self::$testUser, 'payment', '(CustomerInvoicePayment)', self::$bankAccountId, '', '');
		$this->assertGreaterThan(0, $bankId, 'Customer refund bank line creation should succeed.');

		$payment->fetch((int) $paymentId);

		return $payment;
	}

	/**
	 * Create a native supplier payment for a standard supplier invoice.
	 *
	 * @param FactureFournisseur $invoice Supplier invoice.
	 * @param float              $amount Payment amount.
	 * @return PaiementFourn
	 */
	protected function createSupplierPayment(FactureFournisseur $invoice, float $amount): PaiementFourn
	{
		global $conf;

		$thirdparty = new Societe(self::$dbHandle);
		$thirdparty->fetch((int) $invoice->socid);

		$payment = new PaiementFourn(self::$dbHandle);
		$payment->datepaye = dol_now();
		$payment->amounts = array((int) $invoice->id => abs($amount));
		$paymentCurrencyCode = !empty($invoice->multicurrency_code) ? (string) $invoice->multicurrency_code : (string) $conf->currency;
		$paymentCurrencyTx = !empty($invoice->multicurrency_tx) ? (float) $invoice->multicurrency_tx : 1.0;
		$payment->multicurrency_amounts = array((int) $invoice->id => abs($amount));
		$payment->multicurrency_code = array((int) $invoice->id => $paymentCurrencyCode);
		$payment->multicurrency_tx = array((int) $invoice->id => $paymentCurrencyTx);
		$payment->paiementid = self::$paymentModeId;
		$payment->paiementcode = 'VIR';
		$payment->num_payment = 'LFC-SPAY-'.uniqid();
		$payment->note_private = 'LibEuFin supplier payment fixture';
		$payment->fk_account = self::$bankAccountId;

		$paymentId = $payment->create(self::$testUser, 1, $thirdparty);
		$this->assertGreaterThan(0, $paymentId, 'Supplier payment creation should succeed.');

		$bankId = $payment->addPaymentToBank(self::$testUser, 'payment_supplier', '(SupplierInvoicePayment)', self::$bankAccountId, '', '');
		$this->assertGreaterThan(0, $bankId, 'Supplier payment bank line creation should succeed.');

		$payment->fetch((int) $paymentId);

		return $payment;
	}

	/**
	 * Create a staged incoming transaction fixture.
	 *
	 * @param string $subject Payment subject/message.
	 * @param float  $amount Transaction amount.
	 * @return LibeufinTransaction
	 */
	protected function createIncomingTransaction(string $subject, float $amount): LibeufinTransaction
	{
		global $conf;

		$bankAccount = new Account(self::$dbHandle);
		$bankAccount->fetch(self::$bankAccountId);
		$receivingIban = trim((string) ($bankAccount->iban ?: $bankAccount->iban_prefix));
		if ($receivingIban === '') {
			$receivingIban = self::$receivingIban;
		}

		$transaction = new LibeufinTransaction(self::$dbHandle);
		$transaction->setFromArray(array(
			'entity' => (int) getEntity($transaction->element, 1),
			'external_transaction_id' => 'LFC-TX-'.uniqid(),
			'external_message_id' => 'LFC-MSG-'.uniqid(),
			'external_order_id' => 'LFC-ORDER-'.uniqid(),
			'direction' => LibeufinTransaction::DIRECTION_INCOMING,
			'transaction_status' => LibeufinTransaction::STATUS_NEW,
			'amount' => abs($amount),
			'currency' => !empty($conf->currency) ? (string) $conf->currency : 'EUR',
			'transaction_date' => dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S'),
			'counterparty_iban' => 'CH5604835012345678009',
			'counterparty_bic' => 'POFICHBEXXX',
			'counterparty_name' => 'Integration Counterparty',
			'raw_payload' => array(
				'subject' => $subject,
				'nexus' => array(
					'subject' => $subject,
					'credit_payto' => 'payto://iban/'.$receivingIban.'?receiver-name=Dolibarr',
					'debit_payto' => 'payto://iban/CH5604835012345678009?receiver-name=Integration%20Counterparty',
				),
			),
		));

		$createResult = $transaction->create(self::$testUser, 1);
		$this->assertGreaterThan(0, $createResult, 'Staged incoming transaction creation should succeed.');
		$transaction->fetch((int) $transaction->id);

		return $transaction;
	}
}
