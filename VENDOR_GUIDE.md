# Selling your WooCommerce shop's products on Surplus GY

This guide is for the person who runs the shop. It does not assume you know anything about code, and
nothing here needs a developer. If a step does not look like your screen, stop and send us a
screenshot rather than guessing.

What you get when this is set up:

- everything in your WooCommerce shop appears on Surplus GY, without retyping it
- when you change a price or a stock number in WooCommerce, Surplus GY follows within seconds
- when something sells on Surplus GY, your shop is told, so you do not sell the same item twice
- anything Surplus GY still needs from you is listed on the product itself, so you always know what
  is holding a listing back

---

## 1. Before you start

You need three things.

1. **A Surplus GY seller account** that is approved. If you can sign in at
   <https://surplusgy.com/vendor/login> and see your dashboard, you have one.
2. **Your WooCommerce shop, reachable from the internet.** A shop that only works on your own office
   network, or that lives at an address with a port number in it like `myshop.com:8080`, cannot be
   reached by Surplus GY. Sales will still upload, but we will not be able to tell your shop when
   something sells, and that is how a shop oversells.
3. **Administrator access to your WordPress site**, so you can install a plugin.

You do not need to pause your shop, and nothing you do here changes what your own customers see.

---

## 2. Install the plugin

1. On your Surplus GY dashboard, open **Connected Stores**.
2. Press **Download the WooCommerce plugin**. You get a `.zip` file. Do not unzip it.
3. In WordPress, go to **Plugins**, then **Add New**, then **Upload Plugin**.
4. Choose the `.zip` file you just downloaded and press **Install Now**, then **Activate**.

You will now have a **Surplus GY** item in the WordPress menu on the left.

---

## 3. Connect the two

1. Back on Surplus GY, in **Connected Stores**, press **Add a store** and choose WooCommerce.
2. You are shown a **Key ID** and a **Secret**. The secret is shown **once**. Copy both somewhere
   safe before you leave that page.
3. In WordPress, go to **Surplus GY**, then **Connect**. Paste the Key ID and the Secret and press
   **Connect**.

You should see **Connected to Surplus GY**.

If instead you see a message saying we could not register your site for updates, your shop is working
but Surplus GY cannot call it back. Products will still upload. What will not work is the message
that says "this just sold on Surplus, take one off your shelf", so read section 8 before you carry
on.

---

## 4. Tell us where your categories belong

Surplus GY has its own categories, and yours will not match ours word for word. This is a one-off
job.

Go to **Surplus GY**, then **Import**. The first step is **Map your categories**. Each of your
WooCommerce categories gets a dropdown of Surplus GY categories. Pick the closest one and press save.

You only do this once. Anything you add later in a category you have already mapped is placed
automatically.

---

## 5. Send your products up

On the same **Import** screen, press **Import products to Surplus GY**.

The plugin works through your catalogue in pages and shows you how far it has got. A large shop takes
a few minutes. You can close the tab; it carries on.

**Products with choices work too.** If you sell a shirt in Small, Medium and Large, each size goes up
as its own option with its own price and its own stock number. You do not have to flatten it into one
product, and you should not.

Two kinds of product are left behind, on purpose:

- **Grouped products.** These are a WooCommerce way of listing several products together. Surplus GY
  has no equivalent, and squashing one into a single product would sell the wrong thing.
- **A variation set to "Any".** If one of a variation's choices is left blank in WooCommerce, that
  variation means "any colour" or "any size". Surplus GY needs an exact answer for every choice,
  because that is what a shopper picks and what the price is attached to. Fill the blank in and
  import again. The plugin's log names the exact product.

---

## 6. Fill in what Surplus GY still needs

Surplus GY asks for a few things WooCommerce does not have a box for: the weight, the size of the
packed item, whether VAT applies, the country it was made in, and whether it is a pharmacy item.

Open any product in WordPress. There is a new **Surplus GY** tab beside the usual ones. It lists
exactly what is still outstanding for that product, and you fill it in there. When the list is empty
the product can be approved and go on sale.

You never have to guess what the list is. It comes from Surplus GY itself, so it is always current.

---

## 7. From then on, it keeps itself in step

Once a product is up, you carry on working in WooCommerce exactly as before.

| You change in WooCommerce | What happens on Surplus GY |
|---|---|
| A price | The price follows, converted to Guyana dollars at that day's rate |
| A sale price | The sale follows, and ending the sale ends it there too |
| A stock number | The stock follows |
| A price or stock on one size or colour | Only that size or colour changes |
| Unpublish a product | It comes off sale on Surplus GY. It is never deleted |
| Delete a variation | That option goes to zero stock on Surplus GY, and is kept, because past orders point at it |
| The title or description | Both follow |

It runs within seconds of you pressing Update. If your hosting is slow it can take a minute or two.
There is also an overnight check that compares both sides and reports anything that has drifted apart.

**Two things Surplus GY owns and WooCommerce cannot change**, because they are ours to get right:

- the Surplus GY category
- whether the listing is approved

**One thing you can take back.** If you type a Guyana dollar price directly on Surplus GY, we take
that as you saying "this price is mine now" and we stop letting WooCommerce overwrite it. You can
switch that back off from either side.

---

## 8. When something sells on Surplus GY

Surplus GY tells your shop, and your shop takes that item off its own shelf. That is what stops you
selling the last one twice.

This only works if Surplus GY can reach your site, which is the message in section 3. It also only
applies to products where you have asked WooCommerce to count stock. A product you have deliberately
left as unlimited is left alone.

---

## 9. Bringing Surplus GY products the other way

If you list something on Surplus GY first, you can pull it into WooCommerce. Go to **Surplus GY**,
then **Import from Surplus**, and press import on the ones you want. The category is created for you.

A product with options arrives as a WooCommerce variable product with its options intact, and stays
in step afterwards. Prices are converted into your shop's currency at that day's rate, and the screen
says so. **Check them before you go live.** An exchange rate moves and we would rather you saw the
number than trusted it.

---

## 10. When something looks wrong

Go to **Surplus GY**, then **Logs**. Every attempt is there with a plain sentence saying what
happened and a reference number.

The usual four:

| What you see | What it means | What to do |
|---|---|---|
| "Could not connect" | The key or the secret is wrong, or was regenerated | Make a new connection on Surplus GY and paste the new pair in |
| "batch too large" or "the request body is …" | You sent more in one go than we accept | Nothing. The plugin retries in smaller pieces |
| "unsupported currency" | Your shop's currency has no exchange rate on Surplus GY yet | Tell us your currency and we will add it. Nothing is priced wrongly in the meantime, we simply leave the old price alone |
| "a variation was left out" | One of its choices is set to "Any" | Give that variation an exact value for every choice |

If you are stuck, send us the reference number from the log line. It matches ours exactly, so we can
find the same event on our side in one search.

---

## 11. Stopping

Press **Disconnect** in the plugin, or revoke the key on **Connected Stores**. Both stop the sync
immediately.

Nothing is deleted. Your products stay on Surplus GY and your shop stays exactly as it is. They
simply stop talking to each other.
