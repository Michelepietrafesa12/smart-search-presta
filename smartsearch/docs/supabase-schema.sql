-- SmartSearch Analytics Schema per Supabase/Lovable
-- Esegui queste query nel SQL Editor di Supabase

-- Tabella eventi di ricerca
CREATE TABLE IF NOT EXISTS search_events (
    id BIGSERIAL PRIMARY KEY,
    event_type VARCHAR(50) NOT NULL DEFAULT 'search',
    query TEXT NOT NULL,
    results_count INTEGER DEFAULT 0,
    shop_id VARCHAR(100),
    session_id VARCHAR(100),
    user_agent TEXT,
    device_type VARCHAR(50),
    timestamp TIMESTAMPTZ DEFAULT NOW(),
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Tabella eventi click sui prodotti
CREATE TABLE IF NOT EXISTS click_events (
    id BIGSERIAL PRIMARY KEY,
    event_type VARCHAR(50) NOT NULL DEFAULT 'click',
    query TEXT,
    product_id INTEGER NOT NULL,
    product_name TEXT,
    position INTEGER,
    shop_id VARCHAR(100),
    session_id VARCHAR(100),
    timestamp TIMESTAMPTZ DEFAULT NOW(),
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Tabella conversioni (acquisti dopo ricerca)
CREATE TABLE IF NOT EXISTS conversion_events (
    id BIGSERIAL PRIMARY KEY,
    event_type VARCHAR(50) NOT NULL DEFAULT 'conversion',
    query TEXT,
    product_id INTEGER,
    product_name TEXT,
    order_id INTEGER,
    revenue DECIMAL(10,2) DEFAULT 0,
    shop_id VARCHAR(100),
    session_id VARCHAR(100),
    timestamp TIMESTAMPTZ DEFAULT NOW(),
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Indici per performance
CREATE INDEX IF NOT EXISTS idx_search_events_timestamp ON search_events(timestamp);
CREATE INDEX IF NOT EXISTS idx_search_events_query ON search_events(query);
CREATE INDEX IF NOT EXISTS idx_search_events_shop ON search_events(shop_id);
CREATE INDEX IF NOT EXISTS idx_click_events_timestamp ON click_events(timestamp);
CREATE INDEX IF NOT EXISTS idx_click_events_product ON click_events(product_id);
CREATE INDEX IF NOT EXISTS idx_conversion_events_timestamp ON conversion_events(timestamp);

-- Vista per statistiche giornaliere
CREATE OR REPLACE VIEW daily_search_stats AS
SELECT
    DATE(timestamp) as date,
    shop_id,
    COUNT(*) as total_searches,
    COUNT(DISTINCT session_id) as unique_sessions,
    COUNT(CASE WHEN results_count = 0 THEN 1 END) as no_results_count,
    AVG(results_count) as avg_results
FROM search_events
GROUP BY DATE(timestamp), shop_id
ORDER BY date DESC;

-- Vista per top ricerche
CREATE OR REPLACE VIEW top_searches AS
SELECT
    query,
    shop_id,
    COUNT(*) as search_count,
    AVG(results_count) as avg_results,
    MAX(timestamp) as last_searched
FROM search_events
WHERE timestamp > NOW() - INTERVAL '30 days'
GROUP BY query, shop_id
ORDER BY search_count DESC;

-- Vista per CTR (Click-Through Rate)
CREATE OR REPLACE VIEW search_ctr AS
SELECT
    s.query,
    s.shop_id,
    COUNT(DISTINCT s.id) as searches,
    COUNT(DISTINCT c.id) as clicks,
    ROUND(COUNT(DISTINCT c.id)::DECIMAL / NULLIF(COUNT(DISTINCT s.id), 0) * 100, 2) as ctr_percent
FROM search_events s
LEFT JOIN click_events c ON s.query = c.query AND s.session_id = c.session_id
WHERE s.timestamp > NOW() - INTERVAL '30 days'
GROUP BY s.query, s.shop_id
ORDER BY searches DESC;

-- Vista per revenue da ricerca
CREATE OR REPLACE VIEW search_revenue AS
SELECT
    DATE(c.timestamp) as date,
    c.shop_id,
    COUNT(DISTINCT c.order_id) as orders,
    SUM(c.revenue) as total_revenue,
    COUNT(DISTINCT c.session_id) as converting_sessions
FROM conversion_events c
WHERE c.timestamp > NOW() - INTERVAL '30 days'
GROUP BY DATE(c.timestamp), c.shop_id
ORDER BY date DESC;

-- Vista per ricerche senza risultati
CREATE OR REPLACE VIEW no_results_searches AS
SELECT
    query,
    shop_id,
    COUNT(*) as occurrences,
    MAX(timestamp) as last_occurred
FROM search_events
WHERE results_count = 0 AND timestamp > NOW() - INTERVAL '30 days'
GROUP BY query, shop_id
ORDER BY occurrences DESC;

-- Abilita Row Level Security (opzionale, per multi-tenant)
-- ALTER TABLE search_events ENABLE ROW LEVEL SECURITY;
-- ALTER TABLE click_events ENABLE ROW LEVEL SECURITY;
-- ALTER TABLE conversion_events ENABLE ROW LEVEL SECURITY;

-- Policy per permettere insert anonimi (webhook)
-- CREATE POLICY "Allow anonymous inserts" ON search_events FOR INSERT WITH CHECK (true);
-- CREATE POLICY "Allow anonymous inserts" ON click_events FOR INSERT WITH CHECK (true);
-- CREATE POLICY "Allow anonymous inserts" ON conversion_events FOR INSERT WITH CHECK (true);
