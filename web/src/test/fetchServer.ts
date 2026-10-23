import type { Mock } from 'vitest'

export function respond(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

export interface Call {
  url: string
  method: string
  body: unknown
}

/**
 * Makes a mocked fetch answer by "METHOD /path" (the query is ignored), which suits screens that make several
 * different calls. A handler may answer later by returning a promise. Unknown calls get 404. Returns the calls made.
 */
export function serveApi(fetchMock: Mock, routes: Record<string, () => Response | Promise<Response>>): Call[] {
  const calls: Call[] = []

  fetchMock.mockImplementation((url: string, init: RequestInit = {}) => {
    calls.push({ url, method: init.method ?? 'GET', body: init.body ? JSON.parse(init.body as string) : null })

    const handler = routes[`${init.method ?? 'GET'} ${url.split('?')[0]}`]

    return handler ? Promise.resolve(handler()) : Promise.resolve(respond(404, { message: 'Not found' }))
  })

  return calls
}
