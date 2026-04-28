-- Copyright (C) 2026       Bohdan Potuzhnyi
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see <https://www.gnu.org/licenses/>.


CREATE TABLE IF NOT EXISTS llx_libeufinconnector_transaction(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer NOT NULL DEFAULT 1,
	datec datetime DEFAULT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer DEFAULT NULL,
	fk_user_modif integer DEFAULT NULL,

	external_transaction_id varchar(191) DEFAULT NULL,
	external_message_id varchar(191) DEFAULT NULL,
	external_order_id varchar(191) DEFAULT NULL,
	direction varchar(16) NOT NULL DEFAULT 'incoming',
	transaction_status varchar(16) NOT NULL DEFAULT 'new',

	amount double(24,8) NOT NULL DEFAULT 0,
	currency varchar(16) NOT NULL DEFAULT '',
	transaction_date datetime DEFAULT NULL,

	counterparty_iban varchar(64) DEFAULT NULL,
	counterparty_bic varchar(32) DEFAULT NULL,
	counterparty_name varchar(255) DEFAULT NULL,

	raw_payload mediumtext DEFAULT NULL,
	payload_checksum varchar(64) DEFAULT NULL,
	dedupe_key varchar(191) NOT NULL,

	fk_bank integer DEFAULT NULL,
	fk_paiement integer DEFAULT NULL,
	fk_paiementfourn integer DEFAULT NULL,
	fk_facture integer DEFAULT NULL,
	fk_facture_fourn integer DEFAULT NULL,
	fk_prelevement_bons integer DEFAULT NULL
) ENGINE=innodb;
