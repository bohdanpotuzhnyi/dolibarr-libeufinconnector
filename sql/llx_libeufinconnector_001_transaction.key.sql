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


ALTER TABLE llx_libeufinconnector_transaction ADD INDEX idx_libeufinconnector_transaction_rowid (rowid);
ALTER TABLE llx_libeufinconnector_transaction ADD INDEX idx_libeufinconnector_transaction_entity (entity);
ALTER TABLE llx_libeufinconnector_transaction ADD INDEX idx_libeufinconnector_transaction_direction (direction);
ALTER TABLE llx_libeufinconnector_transaction ADD INDEX idx_libeufinconnector_transaction_status (transaction_status);
ALTER TABLE llx_libeufinconnector_transaction ADD INDEX idx_libeufinconnector_transaction_date (transaction_date);
ALTER TABLE llx_libeufinconnector_transaction ADD INDEX idx_libeufinconnector_transaction_message (external_message_id);
ALTER TABLE llx_libeufinconnector_transaction ADD INDEX idx_libeufinconnector_transaction_order (external_order_id);
ALTER TABLE llx_libeufinconnector_transaction ADD INDEX idx_libeufinconnector_transaction_checksum (payload_checksum);
ALTER TABLE llx_libeufinconnector_transaction ADD INDEX idx_libeufinconnector_transaction_fk_bank (fk_bank);
ALTER TABLE llx_libeufinconnector_transaction ADD INDEX idx_libeufinconnector_transaction_fk_paiement (fk_paiement);
ALTER TABLE llx_libeufinconnector_transaction ADD INDEX idx_libeufinconnector_transaction_fk_paiementfourn (fk_paiementfourn);
ALTER TABLE llx_libeufinconnector_transaction ADD INDEX idx_libeufinconnector_transaction_fk_facture (fk_facture);
ALTER TABLE llx_libeufinconnector_transaction ADD INDEX idx_libeufinconnector_transaction_fk_facture_fourn (fk_facture_fourn);
ALTER TABLE llx_libeufinconnector_transaction ADD INDEX idx_libeufinconnector_transaction_fk_prelevement_bons (fk_prelevement_bons);
ALTER TABLE llx_libeufinconnector_transaction ADD UNIQUE INDEX uk_libeufinconnector_transaction_entity_dedupe (entity, dedupe_key);
ALTER TABLE llx_libeufinconnector_transaction ADD UNIQUE INDEX uk_libeufinconnector_transaction_entity_direction_exttx (entity, direction, external_transaction_id);
