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

require_once __DIR__.'/LibeufinConnectorIntegrationTestCase.php';

/**
 * Outgoing collection coverage for supplier payments and customer refunds.
 */
class LibeufinOutgoingCollectionIntegrationTest extends LibeufinConnectorIntegrationTestCase
{
	/**
	 * Verify outgoing collection stages supplier payments and customer refund payments.
	 *
	 * @return void
	 */
	public function testCollectOutgoingStagesSupplierPaymentAndCustomerRefund(): void
	{
		$supplierInvoice = $this->createValidatedSupplierInvoice(35.0);
		$supplierPayment = $this->createSupplierPayment($supplierInvoice, 35.0);

		$supplierCreditNote = $this->createValidatedSupplierCreditNote(20.0);
		$supplierRefundPayment = $this->createSupplierPayment($supplierCreditNote, 20.0);

		$customerCreditNote = $this->createValidatedCustomerCreditNote(15.0);
		$customerRefund = $this->createCustomerRefundPayment($customerCreditNote, 15.0);

		libeufinconnectorCollectOutgoingTransactions(self::$dbHandle, self::$testUser);

		$supplierTransaction = new LibeufinTransaction(self::$dbHandle);
		$this->assertGreaterThan(
			0,
			$supplierTransaction->fetchByExternalTransactionId('LFC-SPAY-E'.((int) getEntity($supplierTransaction->element, 1)).'-P'.((int) $supplierPayment->id), LibeufinTransaction::DIRECTION_OUTGOING, (int) getEntity($supplierTransaction->element, 1))
		);
		$this->assertSame((int) $supplierPayment->id, (int) $supplierTransaction->fk_paiementfourn);
		$this->assertSame((int) $supplierInvoice->id, (int) $supplierTransaction->fk_facture_fourn);
		$this->assertSame(
			array('ready' => true, 'issues' => array()),
			libeufinconnectorGetOutgoingTransactionValidation($supplierTransaction)
		);

		$customerTransaction = new LibeufinTransaction(self::$dbHandle);
		$this->assertGreaterThan(
			0,
			$customerTransaction->fetchByExternalTransactionId('LFC-CPAY-E'.((int) getEntity($customerTransaction->element, 1)).'-P'.((int) $customerRefund->id), LibeufinTransaction::DIRECTION_OUTGOING, (int) getEntity($customerTransaction->element, 1))
		);
		$this->assertSame((int) $customerRefund->id, (int) $customerTransaction->fk_paiement);
		$this->assertSame((int) $customerCreditNote->id, (int) $customerTransaction->fk_facture);
		$this->assertSame(LibeufinTransaction::STATUS_NEW, $customerTransaction->transaction_status);
		$this->assertSame(
			array('ready' => true, 'issues' => array()),
			libeufinconnectorGetOutgoingTransactionValidation($customerTransaction)
		);

		$supplierRefundTransaction = new LibeufinTransaction(self::$dbHandle);
		$this->assertSame(
			0,
			$supplierRefundTransaction->fetchByExternalTransactionId('LFC-SPAY-E'.((int) getEntity($supplierRefundTransaction->element, 1)).'-P'.((int) $supplierRefundPayment->id), LibeufinTransaction::DIRECTION_OUTGOING, (int) getEntity($supplierRefundTransaction->element, 1))
		);
	}
}
