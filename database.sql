CREATE TABLE IF NOT EXISTS utilisateurs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nom TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    mot_de_passe TEXT NOT NULL,
    date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS favoris (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    utilisateur_id INTEGER NOT NULL,
    seisme_id_externe TEXT NOT NULL,
    lieu TEXT NOT NULL,
    magnitude REAL NOT NULL,
    date_seisme DATETIME NOT NULL,
    latitude REAL NOT NULL,
    longitude REAL NOT NULL,
    date_ajout DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE CASCADE,
    CONSTRAINT uq_user_seisme UNIQUE (utilisateur_id, seisme_id_externe)
);

CREATE TABLE IF NOT EXISTS seismes_demo (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    seisme_id_externe TEXT NOT NULL UNIQUE,
    lieu TEXT NOT NULL,
    magnitude REAL NOT NULL,
    date_seisme DATETIME NOT NULL,
    latitude REAL NOT NULL,
    longitude REAL NOT NULL
);

-- Demo account: demo@earthquakewatcher.net / password: Station2026!
INSERT OR IGNORE INTO utilisateurs (id, nom, email, mot_de_passe)
VALUES (1, 'Utilisateur Démo', 'demo@earthquakewatcher.net', '$2y$10$Gv/fVfBSKZDm14L7ea0NTeZWCoE2vMhePa0iSXCRbR19nCF3aj5TG');

-- 10 real reference earthquakes 
INSERT OR IGNORE INTO seismes_demo (seisme_id_externe, lieu, magnitude, date_seisme, latitude, longitude) VALUES
('demo_001', 'Off the east coast of Honshu, Japan (Tohoku)', 9.1, '2011-03-11 05:46:24', 38.2970, 142.3730),
('demo_002', 'Banda Aceh, Sumatra, Indonesia', 9.1, '2004-12-26 00:58:53', 3.3160, 95.8540),
('demo_003', 'Kahramanmaras, Turkey', 7.8, '2023-02-06 01:17:34', 37.1660, 37.0420),
('demo_004', 'Port-au-Prince region, Haiti', 7.0, '2010-01-12 21:53:10', 18.4570, -72.5330),
('demo_005', 'Valdivia, Chile', 9.5, '1960-05-22 19:11:14', -38.2900, -73.0500),
('demo_006', 'Prince William Sound, Alaska, USA', 9.2, '1964-03-28 03:36:16', 61.0200, -147.6500),
('demo_007', 'San Francisco, California, USA', 7.9, '1906-04-18 13:12:00', 37.7500, -122.5500),
('demo_008', 'Kobe, Hyogo, Japan', 6.9, '1995-01-16 20:46:52', 34.5800, 135.0200),
('demo_009', 'L''Aquila, Abruzzo, Italy', 6.3, '2009-04-06 01:32:39', 42.3340, 13.3340),
('demo_010', 'Eastern Crete, Greece', 6.4, '2021-10-12 09:24:03', 35.1900, 25.3200);

-- Initial favourite of the demo user
INSERT OR IGNORE INTO favoris (utilisateur_id, seisme_id_externe, lieu, magnitude, date_seisme, latitude, longitude)
VALUES (1, 'demo_001', 'Off the east coast of Honshu, Japan (Tohoku)', 9.1, '2011-03-11 05:46:24', 38.2970, 142.3730);