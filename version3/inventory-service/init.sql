CREATE TABLE IF NOT EXISTS products (
  id BIGSERIAL PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  unit_price NUMERIC(10,2) NOT NULL CHECK (unit_price >= 0),
  stock INTEGER NOT NULL CHECK (stock >= 0)
);

CREATE TABLE IF NOT EXISTS reservations (
  reservation_key VARCHAR(120) PRIMARY KEY,
  product_id BIGINT NOT NULL REFERENCES products(id),
  quantity INTEGER NOT NULL CHECK (quantity > 0),
  total NUMERIC(12,2) NOT NULL,
  state VARCHAR(16) NOT NULL CHECK (state IN ('RESERVED', 'RELEASED')),
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

INSERT INTO products (id, name, unit_price, stock) VALUES
  (1, 'Clavier mécanique', 79.90, 100),
  (2, 'Écran 27 pouces', 219.00, 50),
  (3, 'Souris sans fil', 34.50, 200)
ON CONFLICT (id) DO NOTHING;

SELECT setval(pg_get_serial_sequence('products', 'id'), (SELECT MAX(id) FROM products));
