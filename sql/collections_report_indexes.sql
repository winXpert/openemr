-- Collections report lookup indexes. Apply once per site database after checking
-- SHOW INDEX for equivalent indexes. No billing or patient records are changed.
-- The first index covers insurance effective-date lookups and the returned payer.
CREATE INDEX collections_coverage ON insurance_data (pid, type, date, date_end, provider);
CREATE INDEX collections_active_invoice ON ar_activity (deleted, pid, encounter);
CREATE INDEX collections_product_invoice ON drug_sales (pid, encounter);
