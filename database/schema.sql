-- ============================================================
-- FinApp v3 — Schema SQLite Completo
-- Gerado automaticamente pelo instalador
-- ============================================================

PRAGMA foreign_keys = ON;
PRAGMA journal_mode = WAL;

-- ============================================================
-- AUTENTICAÇÃO
-- ============================================================
CREATE TABLE IF NOT EXISTS usuario (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    email         TEXT,
    reset_token   TEXT,
    reset_expires DATETIME,
    criado_em     DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- CONFIGURAÇÕES DO SISTEMA
-- ============================================================
CREATE TABLE IF NOT EXISTS config_sistema (
    chave      TEXT PRIMARY KEY,
    valor      TEXT,
    criado_em  DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- CLIENTES
-- ============================================================
CREATE TABLE IF NOT EXISTS clientes (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    nome      TEXT NOT NULL,
    cpf_cnpj  TEXT,
    endereco  TEXT,
    telefone  TEXT,
    email     TEXT,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- CONTAS BANCÁRIAS
-- ============================================================
CREATE TABLE IF NOT EXISTS contas (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    nome          TEXT NOT NULL,
    tipo_conta    TEXT NOT NULL DEFAULT 'corrente',
    saldo_inicial REAL NOT NULL DEFAULT 0,
    ativa         INTEGER DEFAULT 1,
    criado_em     DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- CATEGORIAS
-- ============================================================
CREATE TABLE IF NOT EXISTS categorias (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    nome      TEXT NOT NULL,
    tipo      TEXT NOT NULL CHECK(tipo IN ('entrada','saida')),
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- MÉTODOS DE PAGAMENTO
-- ============================================================
CREATE TABLE IF NOT EXISTS metodos (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    nome             TEXT NOT NULL,
    tem_taxa         INTEGER DEFAULT 0,
    taxa_tipo        TEXT DEFAULT 'percentual',
    taxa_valor       REAL DEFAULT 0,
    interno          INTEGER DEFAULT 0
);

-- ============================================================
-- LANÇAMENTOS FINANCEIROS
-- ============================================================
CREATE TABLE IF NOT EXISTS lancamentos (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo             TEXT NOT NULL CHECK(tipo IN ('entrada','saida','transferencia')),
    data             DATE NOT NULL,
    valor            REAL NOT NULL,
    valor_liquido    REAL,
    taxa_valor       REAL DEFAULT 0,
    taxa_tipo        TEXT,
    descricao        TEXT,
    categoria_id     INTEGER,
    cliente_id       INTEGER,
    conta_id         INTEGER NOT NULL,
    conta_destino_id INTEGER,
    metodo_id        INTEGER,
    tem_nf           INTEGER DEFAULT 0,
    num_nf           TEXT,
    mes              INTEGER NOT NULL,
    ano              INTEGER NOT NULL,
    observacoes      TEXT,
    taxa_lancamento_id INTEGER,
    origem_conta_pagar_id INTEGER,
    criado_em        DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (categoria_id)     REFERENCES categorias(id),
    FOREIGN KEY (cliente_id)       REFERENCES clientes(id),
    FOREIGN KEY (conta_id)         REFERENCES contas(id),
    FOREIGN KEY (conta_destino_id) REFERENCES contas(id),
    FOREIGN KEY (metodo_id)        REFERENCES metodos(id)
);

-- ============================================================
-- SALDOS INICIAIS
-- ============================================================
CREATE TABLE IF NOT EXISTS saldos_iniciais (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    conta_id INTEGER NOT NULL,
    mes      INTEGER NOT NULL,
    ano      INTEGER NOT NULL,
    valor    REAL NOT NULL DEFAULT 0,
    UNIQUE(conta_id, mes, ano),
    FOREIGN KEY (conta_id) REFERENCES contas(id)
);

-- ============================================================
-- MESES FECHADOS
-- ============================================================
CREATE TABLE IF NOT EXISTS meses_fechados (
    mes        INTEGER NOT NULL,
    ano        INTEGER NOT NULL,
    fechado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    reaberto   INTEGER DEFAULT 0,
    PRIMARY KEY (mes, ano)
);

-- ============================================================
-- CONTROLE DE CAIXAS MENSAIS
-- ============================================================
CREATE TABLE IF NOT EXISTS caixas_abertos (
    mes       INTEGER NOT NULL,
    ano       INTEGER NOT NULL,
    aberto_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (mes, ano)
);

-- ============================================================
-- CARTÕES DE CRÉDITO
-- ============================================================
CREATE TABLE IF NOT EXISTS cartoes (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    nome            TEXT NOT NULL,
    banco           TEXT,
    dia_fechamento  INTEGER DEFAULT 1,
    dia_vencimento  INTEGER DEFAULT 10,
    ativo           INTEGER DEFAULT 1,
    criado_em       DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- FATURAS DO CARTÃO
-- ============================================================
CREATE TABLE IF NOT EXISTS faturas_cartao (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    cartao_id          INTEGER NOT NULL,
    mes_ref            INTEGER NOT NULL,
    ano_ref            INTEGER NOT NULL,
    data_vencimento    DATE,
    valor_total        REAL DEFAULT 0,
    valor_ajustado     REAL,
    valor_pago         REAL DEFAULT 0,
    data_pagamento     DATE,
    conta_pagamento_id INTEGER,
    paga               INTEGER DEFAULT 0,
    lancamento_id      INTEGER,
    observacoes        TEXT,
    fechada            INTEGER DEFAULT 0,
    criado_em          DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cartao_id) REFERENCES cartoes(id),
    FOREIGN KEY (conta_pagamento_id) REFERENCES contas(id)
);

-- ============================================================
-- GASTOS DO CARTÃO
-- ============================================================
CREATE TABLE IF NOT EXISTS gastos_cartao (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    fatura_id   INTEGER NOT NULL,
    cartao_id   INTEGER NOT NULL,
    data_gasto  DATE NOT NULL,
    descricao   TEXT,
    categoria_id INTEGER,
    valor       REAL NOT NULL,
    criado_em   DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (fatura_id)    REFERENCES faturas_cartao(id),
    FOREIGN KEY (cartao_id)    REFERENCES cartoes(id),
    FOREIGN KEY (categoria_id) REFERENCES categorias(id)
);

-- ============================================================
-- CONTAS A PAGAR E A RECEBER
-- ============================================================
CREATE TABLE IF NOT EXISTS contas_pagar_receber (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo                TEXT NOT NULL CHECK(tipo IN ('pagar','receber')),
    data_vencimento     DATE NOT NULL,
    valor               REAL NOT NULL,
    descricao           TEXT,
    cliente_fornecedor  TEXT,
    cliente_id          INTEGER,
    categoria_id        INTEGER,
    conta_id            INTEGER,
    metodo_id           INTEGER,
    pago_recebido       INTEGER DEFAULT 0,
    data_baixa          DATE,
    valor_efetivo       REAL,
    lancamento_id       INTEGER,
    observacoes         TEXT,
    criado_em           DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id)   REFERENCES clientes(id),
    FOREIGN KEY (categoria_id) REFERENCES categorias(id),
    FOREIGN KEY (conta_id)     REFERENCES contas(id),
    FOREIGN KEY (metodo_id)    REFERENCES metodos(id)
);

-- ============================================================
-- IMPOSTOS POR MÊS
-- ============================================================
CREATE TABLE IF NOT EXISTS impostos_mes (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    mes           INTEGER NOT NULL,
    ano           INTEGER NOT NULL,
    descricao     TEXT NOT NULL,
    tipo          TEXT NOT NULL DEFAULT 'fixo',
    valor         REAL NOT NULL DEFAULT 0,
    lancamento_id INTEGER,
    criado_em     DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- VAULT CRIPTOGRAFADO
-- ============================================================
CREATE TABLE IF NOT EXISTS id_key_token (
    id        INTEGER PRIMARY KEY CHECK(id=1),
    hash      TEXT NOT NULL,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS id_key_vault (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    titulo        TEXT NOT NULL,
    conteudo      TEXT NOT NULL,
    iv            TEXT NOT NULL,
    criado_em     DATETIME DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- AUDITORIA
-- ============================================================
CREATE TABLE IF NOT EXISTS audit_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario    TEXT,
    acao       TEXT NOT NULL,
    modulo     TEXT,
    detalhe    TEXT,
    ip         TEXT,
    criado_em  DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- DADOS PADRÃO (inseridos pelo instalador, não hardcoded aqui)
-- ============================================================
-- Os seeds são inseridos pelo install/steps/step4.php após criação do admin
