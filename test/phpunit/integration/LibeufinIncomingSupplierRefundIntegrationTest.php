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
 * Incoming supplier refund matching integration coverage.
 */
class LibeufinIncomingSupplierRefundIntegrationTest extends LibeufinConnectorIntegrationTestCase
{
	/**
	 * Verify a staged incoming supplier refund creates native SPAY + bank line.
	 *
	 * @return void
	 */
	public function testIncomingSupplierCreditNoteMatchCreatesSupplierPayment(): void
	{
		$creditNote = $this->createValidatedSupplierCreditNote(50.0);
		$transaction = $this->createIncomingTransaction($creditNote->ref.' partial supplier refund', 20.0);

		$result = libeufinconnectorApplyIncomingInvoiceMatch(self::$dbHandle, $transaction, self::$testUser);

		$this->assertTrue((bool) $result['ok']);
		$this->assertSame('created', $result['status']);
		$this->assertSame((int) $creditNote->id, (int) $result['supplier_invoice_id']);

		$transaction->fetch((int) $transaction->id);
		$this->assertGreaterThan(0, (int) $transaction->fk_paiementfourn);
		$this->assertGreaterThan(0, (int) $transaction->fk_bank);
		$this->assertSame((int) $creditNote->id, (int) $transaction->fk_facture_fourn);
		$this->assertSame(LibeufinTransaction::STATUS_MATCHED, $transaction->transaction_status);

		$payment = new PaiementFourn(self::$dbHandle);
		$this->assertGreaterThan(0, $payment->fetch((int) $transaction->fk_paiementfourn));
		$this->assertSame((int) $transaction->fk_bank, (int) $payment->bank_line);
		$this->assertSame(-20.0, (float) price2num((float) $payment->amount, 'MT'));
	}
}
