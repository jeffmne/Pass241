import { fetchTickets } from '../api/agent';
import { NetworkError } from '../api/client';
import { readMeta, writeMeta } from '../db/database';
import { upsertTickets } from '../db/tickets';
import { downloadTickets } from '../services/sync';

jest.mock('../api/agent', () => ({ fetchTickets: jest.fn(), pushScans: jest.fn() }));
jest.mock('../db/database', () => ({ readMeta: jest.fn(), writeMeta: jest.fn() }));
jest.mock('../db/tickets', () => ({ upsertTickets: jest.fn() }));
jest.mock('../db/scans', () => ({ applyOutcomes: jest.fn(), pendingScans: jest.fn() }));

const fetchPage = jest.mocked(fetchTickets);
const metadata = new Map<string, string>();
const first = '2026-09-21T10:00:00Z';
const last = '2026-09-21T10:03:00Z';
const page = (cursor: number | null, synced_at = first) => ({
  tickets: [], has_more: cursor !== null, next_cursor: cursor, synced_at,
});

beforeEach(() => {
  jest.resetAllMocks(); metadata.clear();
  jest.mocked(readMeta).mockImplementation(async (key) => metadata.get(key) ?? null);
  jest.mocked(writeMeta).mockImplementation(async (key, value) => { metadata.set(key, value); });
});

it('conserve la borne de la premiere page pour ne manquer aucune modification', async () => {
  fetchPage.mockResolvedValueOnce(page(500)).mockResolvedValueOnce(page(null, last));
  await downloadTickets('token', 1);
  expect(metadata.get('tickets.since.1')).toBe(first);
});

it('reprend apres la derniere page durable sans avancer since lors de la coupure', async () => {
  fetchPage.mockResolvedValueOnce(page(500)).mockRejectedValueOnce(new NetworkError());
  await expect(downloadTickets('token', 1)).rejects.toBeInstanceOf(NetworkError);
  expect(metadata.has('tickets.since.1')).toBe(false);
  fetchPage.mockResolvedValueOnce(page(null, last));
  await downloadTickets('token', 1);
  expect(fetchPage).toHaveBeenLastCalledWith('token', 1, { since: undefined, cursor: 500 });
  expect(metadata.get('tickets.since.1')).toBe(first);
});

it('ne sauvegarde pas un curseur si le stockage des billets echoue', async () => {
  fetchPage.mockResolvedValueOnce(page(500));
  jest.mocked(upsertTickets).mockRejectedValueOnce(new Error('disk full'));
  await expect(downloadTickets('token', 1)).rejects.toThrow('disk full');
  expect(metadata.size).toBe(0);
});

it('partage un seul telechargement entre deux ecrans', async () => {
  fetchPage.mockResolvedValue(page(null));
  await Promise.all([downloadTickets('token', 1), downloadTickets('token', 1)]);
  expect(fetchPage).toHaveBeenCalledTimes(1);
});

it('arrete une pagination qui ne progresse pas', async () => {
  fetchPage.mockResolvedValue(page(500));
  await expect(downloadTickets('token', 1)).rejects.toBeInstanceOf(NetworkError);
  expect(fetchPage).toHaveBeenCalledTimes(2);
});
