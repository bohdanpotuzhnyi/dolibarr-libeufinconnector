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
 * Incoming customer payment matching integration coverage.
 */
class LibeufinIncomingCustomerPaymentIntegrationTest extends LibeufinConnectorIntegrationTestCase
{
	/**
	 * Verify a staged incoming payment creates customer payment + bank line when the invoice ref is present.
	 *
	 * @return void
	 */
	public function testIncomingCustomerInvoiceMatchCreatesPaymentAndBankLine(): void
	{
		$invoice = $this->createValidatedCustomerInvoice(40.0);
		$transaction = $this->createIncomingTransaction($invoice->ref.' partial payment', 25.0);

		$result = libeufinconnectorApplyIncomingInvoiceMatch(self::$dbHandle, $transaction, self::$testUser);

		$this->assertTrue((bool) $result['ok']);
		$this->assertSame('created', $result['status']);
		$this->assertSame((int) $invoice->id, (int) $result['invoice_id']);

		$transaction->fetch((int) $transaction->id);
		$this->assertGreaterThan(0, (int) $transaction->fk_paiement);
		$this->assertGreaterThan(0, (int) $transaction->fk_bank);
		$this->assertSame((int) $invoice->id, (int) $transaction->fk_facture);
		$this->assertSame(LibeufinTransaction::STATUS_MATCHED, $transaction->transaction_status);

		$payment = new Paiement(self::$dbHandle);
		$this->assertGreaterThan(0, $payment->fetch((int) $transaction->fk_paiement));
		$this->assertSame((int) $transaction->fk_bank, (int) $payment->bank_line);

		$amounts = $payment->getAmountsArray();
		$this->assertIsArray($amounts);
		$this->assertArrayHasKey((int) $invoice->id, $amounts);
		$this->assertSame(25.0, (float) $amounts[(int) $invoice->id]);
	}
}
