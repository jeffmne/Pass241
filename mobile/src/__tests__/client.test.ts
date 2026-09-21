import { ApiError, NetworkError, request } from '../api/client';

const originalFetch = globalThis.fetch;

beforeEach(() => { jest.useFakeTimers(); });
afterEach(() => { globalThis.fetch = originalFetch; jest.useRealTimers(); });

it('garde le delai actif pendant le telechargement du corps', async () => {
  globalThis.fetch = jest.fn(async (_url: RequestInfo | URL, options?: RequestInit) => ({
    ok: true,
    status: 200,
    text: () => new Promise<string>((_resolve, reject) => {
      options?.signal?.addEventListener('abort', () => reject(new Error('aborted')));
    }),
  } as Response));
  const result = request({ path: '/test' });
  const assertion = expect(result).rejects.toBeInstanceOf(NetworkError);
  await jest.advanceTimersByTimeAsync(12_000);
  await assertion;
});

it('traite une reponse coupee comme une panne reseau', async () => {
  globalThis.fetch = jest.fn(async () => ({ ok: true, text: async () => { throw new Error('cut'); } } as unknown as Response));
  await expect(request({ path: '/test' })).rejects.toBeInstanceOf(NetworkError);
});

it('ne confond pas un refus du serveur et une coupure', async () => {
  globalThis.fetch = jest.fn(async () => ({ ok: false, status: 403, text: async () => '{}' } as Response));
  await expect(request({ path: '/test' })).rejects.toBeInstanceOf(ApiError);
});

it('refuse un corps JSON incomplet meme avec HTTP 200', async () => {
  globalThis.fetch = jest.fn(async () => ({ ok: true, status: 200, text: async () => '{' } as Response));
  await expect(request({ path: '/test' })).rejects.toBeInstanceOf(NetworkError);
});
