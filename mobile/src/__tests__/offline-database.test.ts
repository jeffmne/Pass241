import { closeDatabase, database, wipeLocalData } from '../db/database';
import { findByReference, saveEvents, savedEvents, upsertTickets } from '../db/tickets';
import { pendingScans, applyOutcomes } from '../db/scans';
import { controlByReference } from '../services/control';

// Execute le vrai SQL SQLite avec Node 24. Seul le pont natif Expo est adapte.
jest.mock('expo-sqlite', () => ({
  openDatabaseAsync: jest.fn(async () => {
    const { DatabaseSync } = require('node:sqlite');
    const native = new DatabaseSync(':memory:');
    const db = {
      execAsync: async (sql: string) => { native.exec(sql); },
      getFirstAsync: async (sql: string, ...params: unknown[]) => native.prepare(sql).get(...params) ?? null,
      getAllAsync: async (sql: string, ...params: unknown[]) => native.prepare(sql).all(...params),
      runAsync: async (sql: string, ...params: unknown[]) => native.prepare(sql).run(...params),
      prepareAsync: async (sql: string) => {
        const statement = native.prepare(sql);
        return { executeAsync: async (...params: unknown[]) => statement.run(...params), finalizeAsync: async () => {} };
      },
      closeAsync: async () => native.close(),
      withExclusiveTransactionAsync: async (work: (tx: unknown) => Promise<void>) => {
        native.exec('BEGIN IMMEDIATE');
        try { await work(db); native.exec('COMMIT'); }
        catch (error) { native.exec('ROLLBACK'); throw error; }
      },
    };
    return db;
  }),
}));

const ticket = { id: 1, reference: 'T-TEST', token_hash: 'hash', holder_name: 'Awa', ticket_type: 'Standard', status: 'ACTIVE' as const, updated_at: null };
beforeEach(async () => { await wipeLocalData(); await upsertTickets(1, [ticket]); });
afterAll(async () => { await closeDatabase(); });

it('accorde une seule entree pour deux controles simultanes hors connexion', async () => {
  const verdicts = await Promise.all([controlByReference(1, 'T-TEST'), controlByReference(1, 'T-TEST')]);
  expect(verdicts.map((v) => v.code)).toEqual(['ACCEPTED', 'ALREADY_USED']);
  expect(await pendingScans(1)).toHaveLength(2);
});

it('annule aussi le scan si le marquage du billet echoue', async () => {
  const db = await database();
  await db.execAsync("CREATE TRIGGER fail_mark BEFORE UPDATE OF status ON tickets BEGIN SELECT RAISE(ABORT, 'disk failure'); END;");
  try {
    await expect(controlByReference(1, 'T-TEST')).rejects.toThrow('disk failure');
    expect(await pendingScans(1)).toHaveLength(0);
    expect((await findByReference(1, 'T-TEST'))?.status).toBe('ACTIVE');
  } finally { await db.execAsync('DROP TRIGGER fail_mark'); }
});

it('une synchronisation ne reactive pas un billet controle localement', async () => {
  await controlByReference(1, 'T-TEST');
  await upsertTickets(1, [ticket]);
  expect((await findByReference(1, 'T-TEST'))?.status).toBe('USED');
});

it('une affectation retiree disparait du cache sans perdre les controles', async () => {
  await controlByReference(1, 'T-TEST');
  await saveEvents([{ id: 1, name: 'Concert', startsAt: null, endsAt: null, venue: null, organization: null, entryPoint: null, ticketsCount: 1 }]);
  await saveEvents([]);
  expect(await savedEvents()).toEqual([]);
  expect(await pendingScans(1)).toHaveLength(1);
});

it('efface le jeton seulement apres confirmation du serveur', async () => {
  await controlByReference(1, 'T-TEST');
  const [scan] = await pendingScans(1);
  const db = await database();
  await db.runAsync('UPDATE scans SET token = ? WHERE id = ?', 'secret-qr', scan.id);
  await applyOutcomes([{ localId: scan.id, confirmed: true, result: 'ACCEPTED', holderName: 'Awa', ticketType: 'Standard', reference: 'T-TEST' }]);
  expect(await pendingScans(1)).toHaveLength(0);
  expect(await db.getFirstAsync('SELECT token, confirmed FROM scans WHERE id = ?', scan.id)).toEqual({ token: null, confirmed: 1 });
});
