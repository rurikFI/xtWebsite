import type { APIRoute } from 'astro';
import { appendFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';

export const prerender = false;

const CSV_PATH = resolve('emails.csv');

export const POST: APIRoute = async ({ request }) => {
  let email: string;

  try {
    const body = await request.json();
    email = (body.email ?? '').trim().toLowerCase();
  } catch {
    return new Response(JSON.stringify({ error: 'Invalid request' }), { status: 400 });
  }

  if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    return new Response(JSON.stringify({ error: 'Invalid email' }), { status: 400 });
  }

  const timestamp = new Date().toISOString();

  if (!existsSync(CSV_PATH)) {
    appendFileSync(CSV_PATH, 'email,timestamp\n', 'utf8');
  }

  appendFileSync(CSV_PATH, `${email},${timestamp}\n`, 'utf8');

  return new Response(JSON.stringify({ ok: true }), { status: 200 });
};
