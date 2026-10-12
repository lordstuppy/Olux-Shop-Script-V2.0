"""Uploads one photo-like JPEG (1600 x 1000) to each of the newest listed
products, through the seller's own product form, so catalogue and product
pages have real images for the browser measurements (webvitals.mjs).

  python3 -I tests/performance/seed-images.py [count]

Test infrastructure only (simulation stack, sellers with the known
simulation password). Deterministic images (fixed seed).
"""
import io
import os
import random
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))
sys.path.insert(0, os.path.join(ROOT, 'tests', 'simulation'))
from PIL import Image, ImageDraw, ImageFilter  # noqa: E402
from simlib import env, http as simhttp, shop  # noqa: E402

simhttp.CA = os.path.join(ROOT, 'tests', 'simulation', 'tls', 'cert.pem')
simhttp.LOOPBACK_PER_CLIENT = False


def photo(seed):
    rnd = random.Random(seed)
    img = Image.new('RGB', (1600, 1000))
    draw = ImageDraw.Draw(img)
    a, b = [tuple(rnd.randrange(256) for _ in range(3)) for _ in range(2)]
    for y in range(1000):
        t = y / 999
        draw.line([(0, y), (1600, y)], fill=tuple(int(a[i] * (1 - t) + b[i] * t) for i in range(3)))
    for _ in range(40):
        x, y, r = rnd.randrange(1600), rnd.randrange(1000), rnd.randrange(20, 220)
        draw.ellipse([x - r, y - r, x + r, y + r], fill=tuple(rnd.randrange(256) for _ in range(3)))
    img = img.filter(ImageFilter.GaussianBlur(2))
    noise = Image.effect_noise((1600, 1000), 24).convert('RGB')
    img = Image.blend(img, noise, 0.12)
    out = io.BytesIO()
    img.save(out, 'JPEG', quality=85)
    return out.getvalue()


def main():
    count = int(sys.argv[1]) if len(sys.argv) > 1 else 24
    rows = env.sql(f"""select p.id, u.email from products p join users u on u.id = p.seller_id
        where p.status = 'active' and u.email like '%@sim.test' and not exists (select 1 from product_images i where i.product_id = p.id)
        order by p.created_at desc, p.id desc limit {count}""")
    clients = {}
    done = 0
    for pid, email in rows:
        c = clients.get(email) or clients.setdefault(email, shop.login(email))
        r = c.form(f'/seller/products/{pid}/edit', f'/seller/products/{pid}/images', {'alt_text': 'Product screenshot'},
                   files={'image': ('photo.jpg', photo(int(pid)), 'image/jpeg')})
        if r.status == 200 and 'Image added' in r.text:
            done += 1
        else:
            print('upload failed for product', pid, r.status, r.flashes())
    # A new image sends a listed product back to review; list them again
    # (what a moderator's approval does) so the pages show them.
    ids = ','.join(pid for pid, _ in rows)
    if ids:
        env.sql(f"update products set status = 'active' where id in ({ids}) and status = 'pending_review'")
    print(f'{done} images uploaded')


if __name__ == '__main__':
    main()
