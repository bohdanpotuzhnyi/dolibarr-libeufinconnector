<?php
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

/**
 * \file        class/libeufintransaction.class.php
 * \ingroup     libeufinconnector
 * \brief       Staging model for imported and submitted LibEuFin transactions
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';

/**
 * Stage LibEuFin transactions before they are linked to Dolibarr business objects.
 */
class LibeufinTransaction extends CommonObject
{
	public const DIRECTION_INCOMING = 'incoming';
	public const DIRECTION_OUTGOING = 'outgoing';

	public const STATUS_NEW = 'new';
	public const STATUS_IMPORTED = 'imported';
	public const STATUS_MATCHED = 'matched';
	public const STATUS_SUBMITTED = 'submitted';
	public const STATUS_BOOKED = 'booked';
	public const STATUS_FAILED = 'failed';
	public const STATUS_IGNORED = 'ignored';

	/** @var string */
	public $module = 'libeufinconnector';

	/** @var string */
	public $element = 'libeufintransaction';

	/** @var string */
	public $table_element = 'libeufinconnector_transaction';

	/** @var string */
	public $picto = 'bank';

	/** @var int */
	public $isextrafieldmanaged = 0;

	/** @var int|string */
	public $ismultientitymanaged = 1;

	/** @var array<string,array<string,mixed>> */
	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'visible' => 0, 'notnull' => 1, 'index' => 1, 'position' => 1),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'visible' => 0, 'notnull' => 1, 'default' => 1, 'index' => 1, 'position' => 5),
		'external_transaction_id' => array('type' => 'varchar(191)', 'label' => 'ExternalTransactionId', 'visible' => 1, 'notnull' => 0, 'index' => 1, 'position' => 10),
		'external_message_id' => array('type' => 'varchar(191)', 'label' => 'ExternalMessageId', 'visible' => 1, 'notnull' => 0, 'index' => 1, 'position' => 11),
		'external_order_id' => array('type' => 'varchar(191)', 'label' => 'ExternalOrderId', 'visible' => 1, 'notnull' => 0, 'index' => 1, 'position' => 12),
		'direction' => array(
			'type' => 'varchar(16)',
			'label' => 'Direction',
			'visible' => 1,
			'notnull' => 1,
			'position' => 20,
			'arrayofkeyval' => array(
				self::DIRECTION_INCOMING => 'Incoming',
				self::DIRECTION_OUTGOING => 'Outgoing',
			),
		),
		'transaction_status' => array(
			'type' => 'varchar(16)',
			'label' => 'Status',
			'visible' => 1,
			'notnull' => 1,
			'position' => 21,
			'arrayofkeyval' => array(
				self::STATUS_NEW => 'New',
				self::STATUS_IMPORTED => 'Imported',
				self::STATUS_MATCHED => 'Matched',
				self::STATUS_SUBMITTED => 'Submitted',
				self::STATUS_BOOKED => 'Booked',
				self::STATUS_FAILED => 'Failed',
				self::STATUS_IGNORED => 'Ignored',
			),
		),
		'amount' => array('type' => 'double(24,8)', 'label' => 'Amount', 'visible' => 1, 'notnull' => 1, 'position' => 30),
		'currency' => array('type' => 'varchar(16)', 'label' => 'Currency', 'visible' => 1, 'notnull' => 1, 'position' => 31),
		'transaction_date' => array('type' => 'datetime', 'label' => 'TransactionDate', 'visible' => 1, 'notnull' => 0, 'position' => 32),
		'counterparty_iban' => array('type' => 'varchar(64)', 'label' => 'CounterpartyIBAN', 'visible' => 1, 'notnull' => 0, 'position' => 40),
		'counterparty_bic' => array('type' => 'varchar(32)', 'label' => 'CounterpartyBIC', 'visible' => 1, 'notnull' => 0, 'position' => 41),
		'counterparty_name' => array('type' => 'varchar(255)', 'label' => 'CounterpartyName', 'visible' => 1, 'notnull' => 0, 'position' => 42),
		'raw_payload' => array('type' => 'text', 'label' => 'RawPayload', 'visible' => -1, 'notnull' => 0, 'position' => 50),
		'payload_checksum' => array('type' => 'varchar(64)', 'label' => 'PayloadChecksum', 'visible' => 0, 'notnull' => 0, 'index' => 1, 'position' => 51),
		'dedupe_key' => array('type' => 'varchar(191)', 'label' => 'DedupeKey', 'visible' => 0, 'notnull' => 1, 'index' => 1, 'position' => 52),
		'fk_bank' => array('type' => 'integer', 'label' => 'BankLine', 'visible' => 1, 'notnull' => 0, 'index' => 1, 'position' => 60),
		'fk_paiement' => array('type' => 'integer', 'label' => 'CustomerPayment', 'visible' => 1, 'notnull' => 0, 'index' => 1, 'position' => 61),
		'fk_paiementfourn' => array('type' => 'integer', 'label' => 'SupplierPayment', 'visible' => 1, 'notnull' => 0, 'index' => 1, 'position' => 62),
		'fk_facture' => array('type' => 'integer', 'label' => 'Invoice', 'visible' => 1, 'notnull' => 0, 'index' => 1, 'position' => 63),
		'fk_facture_fourn' => array('type' => 'integer', 'label' => 'SupplierInvoice', 'visible' => 1, 'notnull' => 0, 'index' => 1, 'position' => 64),
		'fk_prelevement_bons' => array('type' => 'integer', 'label' => 'TransferOrder', 'visible' => 1, 'notnull' => 0, 'index' => 1, 'position' => 65),
		'fk_user_creat' => array('type' => 'integer', 'label' => 'UserAuthor', 'visible' => 0, 'notnull' => 0, 'position' => 500),
		'fk_user_modif' => array('type' => 'integer', 'label' => 'UserModif', 'visible' => 0, 'notnull' => 0, 'position' => 501),
		'datec' => array('type' => 'datetime', 'label' => 'DateCreation', 'visible' => -2, 'notnull' => 0, 'position' => 502),
		'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'visible' => -2, 'notnull' => 0, 'position' => 503),
	);

	public $rowid;
	public $entity;
	public $external_transaction_id;
	public $external_message_id;
	public $external_order_id;
	public $direction;
	public $transaction_status;
	public $amount;
	public $currency;
	public $transaction_date;
	public $counterparty_iban;
	public $counterparty_bic;
	public $counterparty_name;
	public $raw_payload;
	public $payload_checksum;
	public $dedupe_key;
	public $fk_bank;
	public $fk_paiement;
	public $fk_paiementfourn;
	public $fk_facture;
	public $fk_facture_fourn;
	public $fk_prelevement_bons;
	public $fk_user_creat;
	public $fk_user_modif;
	public $datec;
	public $tms;

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler.
	 */
	public function __construct($db)
	{
		$this->db = $db;

		if (!getDolGlobalInt('MAIN_SHOW_TECHNICAL_ID') && isset($this->fields['rowid'])) {
			$this->fields['rowid']['visible'] = 0;
		}

		foreach ($this->fields as $key => $meta) {
			if (isset($meta['enabled']) && empty($meta['enabled'])) {
				unset($this->fields[$key]);
			}
		}
	}

	/**
	 * Create record.
	 *
	 * @param object $user User performing the creation.
	 * @param int    $notrigger Disable triggers when set to 1.
	 * @return int
	 */
	public function create($user, $notrigger = 1)
	{
		$this->normalizeForPersistence();
		if (empty($this->entity)) {
			$this->entity = (int) getEntity($this->element, 1);
		}
		if (empty($this->datec)) {
			$this->datec = dol_now();
		}
		if (empty($this->fk_user_creat) && is_object($user) && isset($user->id)) {
			$this->fk_user_creat = (int) $user->id;
		}

		return $this->createCommon($user, $notrigger);
	}

	/**
	 * Fetch record.
	 *
	 * @param int|string $id Row ID.
	 * @param string     $ref Unused technical ref.
	 * @param int        $noextrafields Disable extrafields fetch.
	 * @param int        $nolines Unused.
	 * @return int
	 */
	public function fetch($id, $ref = '', $noextrafields = 1, $nolines = 1)
	{
		return $this->fetchCommon($id, $ref, '', $noextrafields);
	}

	/**
	 * Update record.
	 *
	 * @param object $user User performing the update.
	 * @param int    $notrigger Disable triggers when set to 1.
	 * @return int
	 */
	public function update($user, $notrigger = 1)
	{
		$this->normalizeForPersistence();
		if (is_object($user) && isset($user->id)) {
			$this->fk_user_modif = (int) $user->id;
		}

		return $this->updateCommon($user, $notrigger);
	}

	/**
	 * Delete record.
	 *
	 * @param object $user User performing the deletion.
	 * @param int    $notrigger Disable triggers when set to 1.
	 * @return int
	 */
	public function delete($user, $notrigger = 1)
	{
		return $this->deleteCommon($user, $notrigger);
	}

	/**
	 * Return supported directions.
	 *
	 * @return array<int,string>
	 */
	public static function getSupportedDirections()
	{
		return array(
			self::DIRECTION_INCOMING,
			self::DIRECTION_OUTGOING,
		);
	}

	/**
	 * Return supported statuses.
	 *
	 * @return array<int,string>
	 */
	public static function getSupportedStatuses()
	{
		return array(
			self::STATUS_NEW,
			self::STATUS_IMPORTED,
			self::STATUS_MATCHED,
			self::STATUS_SUBMITTED,
			self::STATUS_BOOKED,
			self::STATUS_FAILED,
			self::STATUS_IGNORED,
		);
	}

	/**
	 * Normalize a direction value to the persisted vocabulary.
	 *
	 * @param string $direction Direction candidate.
	 * @return string
	 */
	public static function normalizeDirection($direction)
	{
		$direction = strtolower(trim((string) $direction));
		$map = array(
			'in' => self::DIRECTION_INCOMING,
			'inbound' => self::DIRECTION_INCOMING,
			'received' => self::DIRECTION_INCOMING,
			'credit' => self::DIRECTION_INCOMING,
			self::DIRECTION_INCOMING => self::DIRECTION_INCOMING,
			'out' => self::DIRECTION_OUTGOING,
			'outbound' => self::DIRECTION_OUTGOING,
			'sent' => self::DIRECTION_OUTGOING,
			'debit' => self::DIRECTION_OUTGOING,
			self::DIRECTION_OUTGOING => self::DIRECTION_OUTGOING,
		);

		return isset($map[$direction]) ? $map[$direction] : self::DIRECTION_INCOMING;
	}

	/**
	 * Normalize a status value to the persisted vocabulary.
	 *
	 * @param string $status Status candidate.
	 * @return string
	 */
	public static function normalizeStatus($status)
	{
		$status = strtolower(trim((string) $status));
		return in_array($status, self::getSupportedStatuses(), true) ? $status : self::STATUS_NEW;
	}

	/**
	 * Normalize an arbitrary payload into deterministic JSON.
	 *
	 * @param mixed $payload Payload to encode.
	 * @return string
	 */
	public static function normalizePayload($payload)
	{
		if (is_string($payload)) {
			$payload = trim($payload);
			if ($payload === '') {
				return '';
			}

			$decoded = json_decode($payload, true);
			if (json_last_error() === JSON_ERROR_NONE) {
				$payload = $decoded;
			} else {
				return $payload;
			}
		}

		if (is_object($payload)) {
			$payload = json_decode(json_encode($payload), true);
		}

		if (!is_array($payload)) {
			return (string) $payload;
		}

		$payload = self::sortRecursive($payload);
		$json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		return is_string($json) ? $json : '';
	}

	/**
	 * Build a payload checksum.
	 *
	 * @param mixed $payload Payload to hash.
	 * @return string
	 */
	public static function buildPayloadChecksum($payload)
	{
		$normalizedPayload = self::normalizePayload($payload);
		return $normalizedPayload !== '' ? hash('sha256', $normalizedPayload) : '';
	}

	/**
	 * Normalize a date value for persistence.
	 *
	 * @param mixed $value Input date value.
	 * @return string|null
	 */
	public static function normalizeDateTime($value)
	{
		if ($value === null || $value === '') {
			return null;
		}

		if (is_numeric($value)) {
			$timestamp = (int) $value;
		} else {
			$timestamp = strtotime((string) $value);
		}

		if ($timestamp === false || $timestamp <= 0) {
			return null;
		}

		return dol_print_date($timestamp, '%Y-%m-%d %H:%M:%S');
	}

	/**
	 * Build a deterministic dedupe key.
	 *
	 * @param array<string,mixed> $data Input transaction data.
	 * @return string
	 */
	public static function buildDedupeKey(array $data)
	{
		$payloadChecksum = !empty($data['payload_checksum']) ? (string) $data['payload_checksum'] : self::buildPayloadChecksum(isset($data['raw_payload']) ? $data['raw_payload'] : '');
		$transactionDate = self::normalizeDateTime(isset($data['transaction_date']) ? $data['transaction_date'] : null);
		$amount = isset($data['amount']) && $data['amount'] !== '' ? number_format((float) $data['amount'], 8, '.', '') : '0.00000000';
		$parts = array(
			self::normalizeDirection(isset($data['direction']) ? $data['direction'] : self::DIRECTION_INCOMING),
			trim((string) (isset($data['external_transaction_id']) ? $data['external_transaction_id'] : '')),
			trim((string) (isset($data['external_message_id']) ? $data['external_message_id'] : '')),
			trim((string) (isset($data['external_order_id']) ? $data['external_order_id'] : '')),
			$amount,
			strtoupper(trim((string) (isset($data['currency']) ? $data['currency'] : ''))),
			(string) $transactionDate,
			strtoupper(str_replace(' ', '', trim((string) (isset($data['counterparty_iban']) ? $data['counterparty_iban'] : '')))),
			strtoupper(str_replace(' ', '', trim((string) (isset($data['counterparty_bic']) ? $data['counterparty_bic'] : '')))),
			trim((string) (isset($data['counterparty_name']) ? $data['counterparty_name'] : '')),
			$payloadChecksum,
		);

		return hash('sha256', implode("\n", $parts));
	}

	/**
	 * Hydrate object properties from an associative array.
	 *
	 * @param array<string,mixed> $data Input data.
	 * @return void
	 */
	public function setFromArray(array $data)
	{
		$mapping = array(
			'entity',
			'external_transaction_id',
			'external_message_id',
			'external_order_id',
			'direction',
			'transaction_status',
			'amount',
			'currency',
			'transaction_date',
			'counterparty_iban',
			'counterparty_bic',
			'counterparty_name',
			'raw_payload',
			'payload_checksum',
			'dedupe_key',
			'fk_bank',
			'fk_paiement',
			'fk_paiementfourn',
			'fk_facture',
			'fk_facture_fourn',
			'fk_prelevement_bons',
		);

		foreach ($mapping as $field) {
			if (!array_key_exists($field, $data)) {
				continue;
			}

			$this->{$field} = $data[$field];
		}

		$this->normalizeForPersistence();
	}

	/**
	 * Fetch by dedupe key.
	 *
	 * @param string   $dedupeKey Dedupe key.
	 * @param int|null $entity Entity filter.
	 * @return int
	 */
	public function fetchByDedupeKey($dedupeKey, $entity = null)
	{
		$dedupeKey = trim((string) $dedupeKey);
		if ($dedupeKey === '') {
			return 0;
		}

		$sql = "SELECT rowid";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE dedupe_key = '".$this->db->escape($dedupeKey)."'";
		if ($entity !== null) {
			$sql .= " AND entity = ".((int) $entity);
		} else {
			$sql .= " AND entity IN (".getEntity($this->element, true).")";
		}
		$sql .= " ORDER BY rowid DESC";
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			return 0;
		}

		return $this->fetch((int) $obj->rowid);
	}

	/**
	 * Fetch by external transaction id.
	 *
	 * @param string      $externalTransactionId External transaction id.
	 * @param string|null $direction Direction filter.
	 * @param int|null    $entity Entity filter.
	 * @return int
	 */
	public function fetchByExternalTransactionId($externalTransactionId, $direction = null, $entity = null)
	{
		$externalTransactionId = trim((string) $externalTransactionId);
		if ($externalTransactionId === '') {
			return 0;
		}

		$sql = "SELECT rowid";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE external_transaction_id = '".$this->db->escape($externalTransactionId)."'";
		if (!empty($direction)) {
			$sql .= " AND direction = '".$this->db->escape(self::normalizeDirection($direction))."'";
		}
		if ($entity !== null) {
			$sql .= " AND entity = ".((int) $entity);
		} else {
			$sql .= " AND entity IN (".getEntity($this->element, true).")";
		}
		$sql .= " ORDER BY rowid DESC";
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			return 0;
		}

		return $this->fetch((int) $obj->rowid);
	}

	/**
	 * Fetch by any available external identifier.
	 *
	 * @param array<string,mixed> $data Input data containing external identifiers.
	 * @param int|null            $entity Entity filter.
	 * @return int
	 */
	public function fetchByExternalIdentifiers(array $data, $entity = null)
	{
		$conditions = array();
		foreach (array('external_transaction_id', 'external_message_id', 'external_order_id') as $field) {
			if (!empty($data[$field])) {
				$conditions[] = $field." = '".$this->db->escape(trim((string) $data[$field]))."'";
			}
		}

		if (empty($conditions)) {
			return 0;
		}

		$sql = "SELECT rowid";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE (".implode(' OR ', $conditions).")";
		if (!empty($data['direction'])) {
			$sql .= " AND direction = '".$this->db->escape(self::normalizeDirection($data['direction']))."'";
		}
		if ($entity !== null) {
			$sql .= " AND entity = ".((int) $entity);
		} else {
			$sql .= " AND entity IN (".getEntity($this->element, true).")";
		}
		$sql .= " ORDER BY rowid DESC";
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			return 0;
		}

		return $this->fetch((int) $obj->rowid);
	}

	/**
	 * Upsert a staged transaction.
	 *
	 * @param DoliDB            $db Database handler.
	 * @param array<string,mixed> $data Input data.
	 * @param object|null       $user Acting user.
	 * @param int               $notrigger Disable triggers when set to 1.
	 * @return array{ok:bool,action:string,rowid:int,error:string,dedupe_key:string}
	 */
	public static function upsertFromArray($db, array $data, $user = null, $notrigger = 1)
	{
		$globalUser = (isset($GLOBALS['user']) && is_object($GLOBALS['user'])) ? $GLOBALS['user'] : null;
		$actor = is_object($user) ? $user : (is_object($globalUser) ? $globalUser : (object) array('id' => 0));
		$transaction = new self($db);
		$entity = isset($data['entity']) ? (int) $data['entity'] : (int) getEntity($transaction->element, 1);
		$hasStatus = array_key_exists('transaction_status', $data);
		$data['entity'] = $entity;
		$data['direction'] = self::normalizeDirection(isset($data['direction']) ? $data['direction'] : self::DIRECTION_INCOMING);
		if ($hasStatus) {
			$data['transaction_status'] = self::normalizeStatus($data['transaction_status']);
		}
		$data['raw_payload'] = self::normalizePayload(isset($data['raw_payload']) ? $data['raw_payload'] : '');
		$data['payload_checksum'] = !empty($data['payload_checksum']) ? (string) $data['payload_checksum'] : self::buildPayloadChecksum($data['raw_payload']);
		$data['dedupe_key'] = !empty($data['dedupe_key']) ? trim((string) $data['dedupe_key']) : self::buildDedupeKey($data);
		$data['transaction_date'] = self::normalizeDateTime(isset($data['transaction_date']) ? $data['transaction_date'] : null);

		$fetch = $transaction->fetchByDedupeKey($data['dedupe_key'], $entity);
		if ($fetch < 0) {
			return array('ok' => false, 'action' => 'lookup', 'rowid' => 0, 'error' => $transaction->error, 'dedupe_key' => $data['dedupe_key']);
		}

		if ($fetch === 0) {
			$fetch = $transaction->fetchByExternalIdentifiers($data, $entity);
			if ($fetch < 0) {
				return array('ok' => false, 'action' => 'lookup', 'rowid' => 0, 'error' => $transaction->error, 'dedupe_key' => $data['dedupe_key']);
			}
		}

		if (!$hasStatus && $fetch === 0) {
			$data['transaction_status'] = self::STATUS_NEW;
		}

		$transaction->setFromArray($data);
		if ($fetch > 0) {
			$result = $transaction->update($actor, $notrigger);
			return array(
				'ok' => ($result > 0),
				'action' => 'updated',
				'rowid' => ($result > 0 ? (int) $transaction->id : 0),
				'error' => ($result > 0 ? '' : $transaction->error),
				'dedupe_key' => $data['dedupe_key'],
			);
		}

		$result = $transaction->create($actor, $notrigger);
		return array(
			'ok' => ($result > 0),
			'action' => 'created',
			'rowid' => ($result > 0 ? (int) $transaction->id : 0),
			'error' => ($result > 0 ? '' : $transaction->error),
			'dedupe_key' => $data['dedupe_key'],
		);
	}

	/**
	 * Normalize values prior to persistence.
	 *
	 * @return void
	 */
	protected function normalizeForPersistence()
	{
		$this->direction = self::normalizeDirection($this->direction);
		$this->transaction_status = self::normalizeStatus($this->transaction_status);
		$this->currency = strtoupper(trim((string) $this->currency));
		$this->amount = ($this->amount === null || $this->amount === '') ? 0 : (float) $this->amount;
		$this->transaction_date = self::normalizeDateTime($this->transaction_date);
		$this->counterparty_iban = $this->normalizeNullableString($this->counterparty_iban, true);
		$this->counterparty_bic = $this->normalizeNullableString($this->counterparty_bic, true);
		$this->counterparty_name = $this->normalizeNullableString($this->counterparty_name);
		$this->external_transaction_id = $this->normalizeNullableString($this->external_transaction_id);
		$this->external_message_id = $this->normalizeNullableString($this->external_message_id);
		$this->external_order_id = $this->normalizeNullableString($this->external_order_id);
		$this->raw_payload = self::normalizePayload($this->raw_payload);
		$this->payload_checksum = $this->normalizeNullableString($this->payload_checksum, true);
		if (empty($this->payload_checksum) && !empty($this->raw_payload)) {
			$this->payload_checksum = self::buildPayloadChecksum($this->raw_payload);
		}
		if (empty($this->dedupe_key)) {
			$this->dedupe_key = self::buildDedupeKey(array(
				'direction' => $this->direction,
				'external_transaction_id' => $this->external_transaction_id,
				'external_message_id' => $this->external_message_id,
				'external_order_id' => $this->external_order_id,
				'amount' => $this->amount,
				'currency' => $this->currency,
				'transaction_date' => $this->transaction_date,
				'counterparty_iban' => $this->counterparty_iban,
				'counterparty_bic' => $this->counterparty_bic,
				'counterparty_name' => $this->counterparty_name,
				'payload_checksum' => $this->payload_checksum,
			));
		}

		foreach (array('fk_bank', 'fk_paiement', 'fk_paiementfourn', 'fk_facture', 'fk_facture_fourn', 'fk_prelevement_bons') as $field) {
			$this->{$field} = empty($this->{$field}) ? null : (int) $this->{$field};
		}
	}

	/**
	 * Normalize a nullable string.
	 *
	 * @param mixed $value Value to normalize.
	 * @param bool  $uppercase Convert to uppercase.
	 * @return string|null
	 */
	protected function normalizeNullableString($value, $uppercase = false)
	{
		if ($value === null) {
			return null;
		}

		$value = trim((string) $value);
		if ($value === '') {
			return null;
		}

		if ($uppercase) {
			$value = strtoupper(str_replace(' ', '', $value));
		}

		return $value;
	}

	/**
	 * Recursively sort an array for deterministic JSON encoding.
	 *
	 * @param array<mixed> $value Value to sort.
	 * @return array<mixed>
	 */
	protected static function sortRecursive(array $value)
	{
		foreach ($value as $key => $item) {
			if (is_object($item)) {
				$item = json_decode(json_encode($item), true);
			}
			if (is_array($item)) {
				$value[$key] = self::sortRecursive($item);
			}
		}

		if (self::isAssoc($value)) {
			ksort($value);
		}

		return $value;
	}

	/**
	 * Tell whether the array is associative.
	 *
	 * @param array<mixed> $value Array to inspect.
	 * @return bool
	 */
	protected static function isAssoc(array $value)
	{
		if ($value === array()) {
			return false;
		}

		return array_keys($value) !== range(0, count($value) - 1);
	}
}
