import type { APIRoute } from 'astro';
import Stripe from 'stripe';

export const prerender = false;

const stripe = new Stripe(import.meta.env.STRIPE_SECRET_KEY);

const BASE_PRICE_CENTS = 2999; // €29.99

function getDiscountedPriceCents(qty: number): number {
  if (qty >= 10) return Math.round(BASE_PRICE_CENTS * 0.70);
  if (qty >= 6)  return Math.round(BASE_PRICE_CENTS * 0.75);
  if (qty >= 3)  return Math.round(BASE_PRICE_CENTS * 0.90);
  return BASE_PRICE_CENTS;
}

function getDiscountLabel(qty: number): string {
  if (qty >= 10) return '30% off — best deal';
  if (qty >= 6)  return '25% off';
  if (qty >= 3)  return '10% off';
  return 'Standard price';
}

export const POST: APIRoute = async ({ request }) => {
  let sizes: string[];

  try {
    const body = await request.json();
    sizes = (body.sizes ?? []).map((s: unknown) => String(s).trim()).filter(Boolean);
  } catch {
    return new Response(JSON.stringify({ error: 'Invalid request' }), { status: 400 });
  }

  if (!sizes.length || sizes.length > 20) {
    return new Response(JSON.stringify({ error: 'Between 1 and 20 Xtruders required' }), { status: 400 });
  }

  const qty = sizes.length;
  const unitPriceCents = getDiscountedPriceCents(qty);
  const origin = request.headers.get('origin') ?? 'http://localhost:4321';

  const session = await stripe.checkout.sessions.create({
    mode: 'payment',
    currency: 'eur',
    automatic_tax: { enabled: true },
    line_items: [
      {
        quantity: qty,
        price_data: {
          currency: 'eur',
          unit_amount: unitPriceCents,
          product_data: {
            name: `Xtruder™ Custom Kit — ${qty} unit${qty > 1 ? 's' : ''}`,
            description: `${getDiscountLabel(qty)} | Sizes: ${sizes.join(', ')}`,
            images: ['https://www.xtrudertools.com/wp-content/uploads/2023/01/xtruder-product.jpg'],
          },
        },
      },
    ],
    metadata: { sizes: sizes.join(','), qty: String(qty) },
    success_url: `${origin}/success?session_id={CHECKOUT_SESSION_ID}`,
    cancel_url: `${origin}/cancel`,
  });

  return new Response(JSON.stringify({ url: session.url }), { status: 200 });
};
