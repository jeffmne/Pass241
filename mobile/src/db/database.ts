import * as SQLite from 'expo-sqlite';

/**
 * La base locale (section 27.10).
 *
 * C'est elle qui rend le mode hors connexion possible : sans elle, une porte
 * sans reseau ne controle plus rien. Elle contient le strict necessaire au
 * controle, et rien de plus (section 27.5).
 *
 * CE QU'ELLE NE CONTIENT PAS, et c'est deliberé :
 *
 * - aucun jeton de billet — seulement des EMPREINTES. Un telephone d'agent se
 *   perd ; avec les jetons, celui qui le ramasse peut fabriquer des QR, avec
 *   les empreintes il ne peut que reconnaitre un billet ;
 * - aucun email, aucun numero de telephone d'acheteur (section 27.5) ;
 * - aucun mot de passe ni jeton de session : ceux-la vivent dans SecureStore,
 *   qui est chiffre par le systeme.
 *
 * Le contenu brut d'un QR n'est conserve QUE pour les controles pas encore
 * remontes — le serveur en a besoin pour verifier la signature — et il est
 * efface des que le serveur a repondu. L'exposition se limite donc aux scans
 * d'une porte hors connexion, portant sur des billets deja passes.
 */

const DATABASE_NAME = 'pass241-agent.db';

let instance: SQLite.SQLiteDatabase | null = null;
let opening: Promise<SQLite.SQLiteDatabase> | null = null;
let writes: Promise<unknown> = Promise.resolve();

export async function database(): Promise<SQLite.SQLiteDatabase> {
  if (instance) {
    return instance;
  }

  opening ??= (async () => {
    const db = await SQLite.openDatabaseAsync(DATABASE_NAME);
    try {
      await db.execAsync('PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 5000;');
      await migrate(db);
      instance = db;
      return db;
    } catch (error) {
      await db.closeAsync();
      throw error;
    }
  })();

  try {
    return await opening;
  } finally {
    opening = null;
  }
}

/** Une seule ecriture a la fois ; aucune requete exterieure ne rejoint le commit. */
export function writeTransaction<T>(work: (tx: SQLite.SQLiteDatabase) => Promise<T>): Promise<T> {
  const task = writes.then(async () => {
    const db = await database();
    let result!: T;
    await db.withExclusiveTransactionAsync(async (tx) => {
      result = await work(tx);
    });
    return result;
  });
  writes = task.catch(() => undefined);
  return task;
}

/**
 * Migrations, versionnees par `user_version`.
 *
 * Une application agent se met a jour entre deux evenements, parfois avec une
 * file de scans non synchronises dans la base. Recreer les tables a chaque
 * version perdrait ces controles : les migrations sont donc additives, et le
 * numero de version dit ou l'on en est.
 */
async function migrate(db: SQLite.SQLiteDatabase): Promise<void> {
  const row = await db.getFirstAsync<{ user_version: number }>('PRAGMA user_version');
  const version = row?.user_version ?? 0;

  if (version < 1) {
    await db.execAsync(`
      PRAGMA journal_mode = WAL;

      CREATE TABLE IF NOT EXISTS events (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        starts_at TEXT,
        ends_at TEXT,
        venue TEXT,
        organization TEXT,
        entry_point_id INTEGER,
        entry_point_name TEXT,
        tickets_count INTEGER NOT NULL DEFAULT 0,
        synced_at TEXT
      );

      CREATE TABLE IF NOT EXISTS tickets (
        id INTEGER PRIMARY KEY,
        event_id INTEGER NOT NULL,
        reference TEXT NOT NULL,
        token_hash TEXT NOT NULL,
        holder_name TEXT,
        ticket_type TEXT,
        status TEXT NOT NULL,
        used_at TEXT,
        updated_at TEXT
      );

      -- Le scan cherche par empreinte, et doit repondre instantanement :
      -- c'est le geste repete des centaines de fois dans la soiree.
      CREATE INDEX IF NOT EXISTS tickets_by_hash ON tickets (event_id, token_hash);
      CREATE INDEX IF NOT EXISTS tickets_by_reference ON tickets (event_id, reference);
      CREATE INDEX IF NOT EXISTS tickets_by_holder ON tickets (event_id, holder_name);

      CREATE TABLE IF NOT EXISTS scans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id INTEGER NOT NULL,
        ticket_id INTEGER,
        reference TEXT,
        token TEXT,
        result TEXT NOT NULL,
        confirmed INTEGER NOT NULL DEFAULT 0,
        scanned_at TEXT NOT NULL,
        manual INTEGER NOT NULL DEFAULT 0,
        holder_name TEXT,
        ticket_type TEXT
      );

      -- La file se lit dans l'ordre, et l'historique a l'envers : les deux
      -- passent par cet index.
      CREATE INDEX IF NOT EXISTS scans_pending ON scans (event_id, confirmed, id);

      CREATE TABLE IF NOT EXISTS meta (
        key TEXT PRIMARY KEY,
        value TEXT
      );

      PRAGMA user_version = 1;
    `);
  }

  if (version < 2) {
    await db.execAsync(`
      CREATE INDEX IF NOT EXISTS scans_history ON scans (event_id, scanned_at DESC, id DESC);
      PRAGMA user_version = 2;
    `);
  }
}

/** Ferme et oublie la base — utilise a la deconnexion. */
export async function closeDatabase(): Promise<void> {
  await writes;
  if (opening) await opening;
  await instance?.closeAsync();
  instance = null;
}

export async function readMeta(key: string): Promise<string | null> {
  const db = await database();
  const row = await db.getFirstAsync<{ value: string }>('SELECT value FROM meta WHERE key = ?', key);

  return row?.value ?? null;
}

export async function writeMeta(key: string, value: string): Promise<void> {
  await writeTransaction(async (db) => {
    await db.runAsync(
      'INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = excluded.value',
      key,
      value,
    );
  });
}

/**
 * Efface tout ce qui touche au controle.
 *
 * Appelee a la deconnexion : un telephone rendu en fin de soiree ne doit pas
 * garder la liste des participants. Les scans non synchronises partent avec —
 * c'est le prix de la deconnexion, et l'ecran previent avant.
 */
export async function wipeLocalData(): Promise<void> {
  await writeTransaction(async (db) => {
    await db.execAsync('DELETE FROM tickets; DELETE FROM scans; DELETE FROM events; DELETE FROM meta;');
  });
}
