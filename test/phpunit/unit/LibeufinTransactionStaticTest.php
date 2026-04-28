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
require_once DOL_DOCUMENT_ROOT.'/custom/libeufinconnector/class/libeufintransaction.class.php';

/**
 * Static helper coverage for the LibEuFin transaction staging model.
 *
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class LibeufinTransactionStaticTest extends CommonClassTest
{
	/**
	 * Verify direction aliases collapse into the persisted vocabulary.
	 *
	 * @return void
	 */
	public function testNormalizeDirectionAliases(): void
	{
		$this->assertSame(LibeufinTransaction::DIRECTION_INCOMING, LibeufinTransaction::normalizeDirection('credit'));
		$this->assertSame(LibeufinTransaction::DIRECTION_INCOMING, LibeufinTransaction::normalizeDirection('received'));
		$this->assertSame(LibeufinTransaction::DIRECTION_OUTGOING, LibeufinTransaction::normalizeDirection('debit'));
		$this->assertSame(LibeufinTransaction::DIRECTION_OUTGOING, LibeufinTransaction::normalizeDirection('sent'));
	}

	/**
	 * Verify unsupported statuses fall back to "new".
	 *
	 * @return void
	 */
	public function testNormalizeStatusFallback(): void
	{
		$this->assertSame(LibeufinTransaction::STATUS_BOOKED, LibeufinTransaction::normalizeStatus('booked'));
		$this->assertSame(LibeufinTransaction::STATUS_NEW, LibeufinTransaction::normalizeStatus('something-else'));
	}

	/**
	 * Verify nested payloads are encoded deterministically.
	 *
	 * @return void
	 */
	public function testNormalizePayloadSortsAssociativeKeys(): void
	{
		$payload = array(
			'zeta' => 'last',
			'alpha' => 'first',
			'nested' => array(
				'b' => 2,
				'a' => 1,
			),
		);

		$this->assertSame(
			'{"alpha":"first","nested":{"a":1,"b":2},"zeta":"last"}',
			LibeufinTransaction::normalizePayload($payload)
		);
	}

	/**
	 * Verify equivalent input data produces a stable dedupe key.
	 *
	 * @return void
	 */
	public function testBuildDedupeKeyIsDeterministic(): void
	{
		$dataA = array(
			'direction' => 'incoming',
			'external_transaction_id' => 'TX-001',
			'amount' => '12.50000000',
			'currency' => 'CHF',
			'transaction_date' => '2026-04-27 10:00:00',
			'counterparty_iban' => 'CH93 0076 2011 6238 5295 7',
			'raw_payload' => array('subject' => 'INV-1', 'nexus' => array('subject' => 'INV-1')),
		);
		$dataB = array(
			'direction' => 'credit',
			'external_transaction_id' => 'TX-001',
			'amount' => 12.5,
			'currency' => 'chf',
			'transaction_date' => strtotime('2026-04-27 10:00:00 UTC'),
			'counterparty_iban' => 'CH9300762011623852957',
			'raw_payload' => array('nexus' => array('subject' => 'INV-1'), 'subject' => 'INV-1'),
		);

		$this->assertSame(
			LibeufinTransaction::buildDedupeKey($dataA),
			LibeufinTransaction::buildDedupeKey($dataB)
		);
	}
}
