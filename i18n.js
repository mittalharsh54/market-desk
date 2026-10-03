/* =====================================================================
   Market Desk — English / हिंदी (i18n.js)
   ---------------------------------------------------------------------
   The page is written in English. With Hindi switched on, this file
   swaps every piece of visible text it knows — exact phrases (HI) and
   phrases with numbers in them (HI_RX, SUBS) — as the page renders,
   using a MutationObserver, and swaps it back for English. Longer
   explanations are written in both languages in index.html with
   L(english, hindi). Notes the server builds from known pieces
   ("Label: note", "a · b") are translated piece by piece. Company names
   and descriptions, Yahoo's industry names and news headlines stay in English.
   ===================================================================== */
(function () {
  const KEY = "md-lang";
  let lang = "en";
  try { lang = localStorage.getItem(KEY) === "hi" ? "hi" : "en"; } catch (e) {}

  /* exact phrases (the whole text of one text node, trimmed) */
  const HI = {
    /* sign-in, header, tabs */
    "Indian stock market research. Enter your password.": "भारतीय शेयर बाज़ार रिसर्च। अपना पासवर्ड डालें।",
    "Sign in": "साइन इन", "Sign out": "साइन आउट", "Password": "पासवर्ड", "Wrong password.": "गलत पासवर्ड।",
    "Capital ₹": "पूंजी ₹", "Risk / trade %": "जोखिम / ट्रेड %", "Trades": "सौदे", "Buy & short": "खरीद और शॉर्ट", "Buy only": "सिर्फ़ खरीद", "Leverage": "लीवरेज", "Language": "भाषा",
    "Core portfolio ✓ tested": "कोर पोर्टफ़ोलियो ✓ परखा हुआ", "Paper track record": "पेपर ट्रैक रिकॉर्ड",
    "Paper track record · real prices · after charges · no money invested": "पेपर ट्रैक रिकॉर्ड · असली भाव · चार्ज के बाद · कोई पैसा नहीं लगाया", "Core portfolio · ETFs · delivery (CNC) · checked once a month": "कोर पोर्टफ़ोलियो · ETF · डिलीवरी (CNC) · महीने में एक बार जाँच",
    "Monthly picks ✓ tested": "मासिक चयन ✓ परखा हुआ", "Intraday Top 10": "इंट्राडे टॉप 10", "Market pulse": "बाज़ार की नब्ज़", "Analyze a stock": "शेयर विश्लेषण",
    "Scanner": "स्कैनर", "India macro inputs": "भारत के आर्थिक आंकड़े", "How it works": "यह कैसे काम करता है",
    "NSE open": "NSE खुला", "NSE closed": "NSE बंद", "NSE Pre-market": "NSE प्री-मार्केट",
    /* common buttons & words */
    "↻ Refresh": "↻ रिफ़्रेश", "Refresh": "रिफ़्रेश", "Re-pick": "दोबारा चुनें", "Analyze": "विश्लेषण करें", "Run scan": "स्कैन चलाएँ", "Save inputs": "आंकड़े सेव करें",
    "📱 Telegram on": "📱 टेलीग्राम चालू", "📱 Telegram…": "📱 टेलीग्राम…", "🔔 Enable alerts": "🔔 अलर्ट चालू करें", "🔕 Enable alerts": "🔕 अलर्ट चालू करें", "🔔 Alerts on": "🔔 अलर्ट चालू",
    "✨ AI research note": "✨ AI से पूरी रिपोर्ट", "✨ AI market outlook": "✨ AI बाज़ार दृष्टिकोण", "Try:": "आज़माएँ:", "Recent:": "हाल के:", "edit": "बदलें",
    "Stock": "शेयर", "Do this": "क्या करें", "Shares": "शेयर संख्या", "Enter at": "एंट्री", "Stop-loss": "स्टॉप-लॉस (यहाँ निकलें)", "Target": "लक्ष्य", "Profit / loss": "लाभ / हानि",
    "Price": "भाव", "Price now": "अभी का भाव", "Amount": "राशि", "Price ref.": "संदर्भ भाव", "Qty": "शेयर संख्या", "Entry": "सौदे का भाव", "Stop": "स्टॉप-लॉस", "Upside": "बढ़त की संभावना",
    "Max loss": "ज़्यादा से ज़्यादा नुकसान", "Target 1": "लक्ष्य 1", "Target 2": "लक्ष्य 2", "Target 1 (1.5R)": "लक्ष्य 1", "Target 2 (2.5R)": "लक्ष्य 2", "Target 2 (3R)": "लक्ष्य 2",
    "score": "अंक", "today": "आज", "Up to": "अधिकतम", "Last": "अंतिम", "1 day": "1 दिन", "5 days": "5 दिन", "1 month": "1 महीना", "30 days": "30 दिन", "Index": "इंडेक्स",
    "Instrument": "साधन", "For India": "भारत पर असर", "Why it matters": "क्यों ज़रूरी है", "Momentum": "मोमेंटम", "context only": "सिर्फ़ संदर्भ",
    "headwind": "प्रतिकूल", "neutral": "तटस्थ", "tailwind": "अनुकूल", "up day": "बढ़त वाला दिन", "down day": "गिरावट वाला दिन",
    /* Top 10 */
    "Your budget": "आपका बजट", "Today's profit / loss": "आज का लाभ / हानि", "Right now": "अभी", "No action": "कोई कार्रवाई नहीं", "What to do now": "अभी क्या करें",
    "▼ SHORT trade": "▼ शॉर्ट ट्रेड", "▲ BUY trade": "▲ खरीद ट्रेड", "How to place the orders": "ऑर्डर कैसे लगाएँ", "Why this stock?": "यह शेयर क्यों?",
    "yesterday's close": "कल का बंद भाव", "yesterday's low for now": "फ़िलहाल कल का निचला स्तर", "yesterday's high for now": "फ़िलहाल कल का ऊपरी स्तर",
    "Short if below": "इससे नीचे शॉर्ट करें", "Buy if above": "इससे ऊपर खरीदें", "All research factors": "सभी रिसर्च पहलू", "Track record": "ट्रैक रिकॉर्ड",
    "Rules this desk follows": "इस डेस्क के नियम", "Starts after the first full trading day with a locked list.": "लॉक सूची वाले पहले पूरे ट्रेडिंग दिन के बाद शुरू होगा।",
    "Full chart & analysis →": "पूरा चार्ट और विश्लेषण →", "New signals": "नए सिग्नल", "🐎 Dark horses": "🐎 छुपे रुस्तम", "Could go": "जा सकता है", "In today's list?": "आज की सूची में?",
    "Watch only": "सिर्फ़ नज़र रखें", "Yes": "हाँ", "▲ up": "▲ ऊपर", "▼ down": "▼ नीचे", "Why": "क्यों", "down": "नीचे", "up": "ऊपर",
    "WAIT — market not open yet": "रुकें — बाज़ार अभी खुला नहीं", "WAIT — no signal yet": "रुकें — अभी कोई सिग्नल नहीं", "BUY NOW": "अभी खरीदें", "SELL NOW (short)": "अभी बेचें (शॉर्ट)",
    "HOLD — you are in": "होल्ड करें — आप ट्रेड में हैं", "HOLD — short is open": "होल्ड करें — शॉर्ट खुला है", "HOLD — half booked": "होल्ड करें — आधा मुनाफ़ा बुक",
    "SELL HALF NOW — Target 1 hit": "अभी आधा बेचें — टारगेट 1 पूरा", "BUY BACK HALF NOW — Target 1 hit": "अभी आधा वापस खरीदें — टारगेट 1 पूरा",
    "CLOSED — PROFIT": "बंद — मुनाफ़ा", "CLOSED — LOSS": "बंद — नुकसान", "NO TRADE TODAY": "आज कोई ट्रेड नहीं", "SKIPPED": "छोड़ा गया",
    "Price never gave a clean signal — nothing to do.": "भाव ने साफ़ सिग्नल नहीं दिया — कुछ करने की ज़रूरत नहीं।",
    "A signal came but the day's limits were already reached.": "सिग्नल आया, पर दिन की सीमा पहले ही पूरी हो चुकी थी।",
    "Stop-loss (moved)": "स्टॉप-लॉस (बदला गया)", "Profit / loss now": "अभी लाभ / हानि", "after charges": "चार्ज के बाद", "Result": "नतीजा",
    "exit all if price falls here": "भाव यहाँ गिरे तो पूरा बेच दें", "buy back all if price rises here": "भाव यहाँ चढ़े तो पूरा वापस खरीदें",
    "sell half here": "यहाँ आधा बेचें", "buy back half here": "यहाँ आधा वापस खरीदें", "done — half booked": "पूरा — आधा बुक", "sell the rest here": "बाकी यहाँ बेचें", "buy back the rest here": "बाकी यहाँ वापस खरीदें",
    "PRE-OPEN": "बाज़ार खुलने से पहले", "WAITING": "इंतज़ार", "LONG ACTIVE": "खरीद चालू", "SHORT ACTIVE": "शॉर्ट चालू", "NO DATA": "डेटा नहीं", "SIGNAL SKIPPED": "सिग्नल छोड़ा",
    "BUY": "खरीदें", "SELL": "बेचें", "HOLD": "रखे रहें", "BUY (wait)": "खरीद का संकेत (अभी सौदे का समय नहीं)", "SELL (wait)": "बिक्री का संकेत (अभी सौदे का समय नहीं)", "WAIT": "रुकें", "AVOID": "दूर रहें",
    "SELL / EXIT": "बेचें / निकलें", "SELL / AVOID": "बेचें / दूर रहें", "ACCUMULATE": "थोड़ा-थोड़ा खरीदें", "STRONG BUY": "ज़ोरदार खरीद",
    /* Monthly picks */
    "Monthly momentum · delivery (CNC) · checked once a month": "मासिक मोमेंटम · डिलीवरी (CNC) · महीने में एक बार जाँच",
    "Market filter": "बाज़ार फ़िल्टर", "ON — invest": "चालू — निवेश करें", "OFF — hold cash": "बंद — नकद रखें", "This month": "इस महीने", "Starts": "शुरुआत", "Next check": "अगली जाँच",
    "first trading day of the month": "महीने का पहला ट्रेडिंग दिन", "What to hold this month": "इस महीने क्या रखें", "Top 20 by momentum": "मोमेंटम में टॉप 20",
    "12-1 month": "12-1 महीना", "Last month": "पिछला महीना", "Above 200-DMA": "200-DMA से ऊपर", "KEEP": "रखें", "BUY (delivery)": "खरीदें (डिलीवरी)",
    /* market pulse */
    "Market regime for Indian equities": "भारतीय शेयरों के लिए बाज़ार का माहौल", "What's moving the market": "बाज़ार को क्या चला रहा है", "factor score · weight": "पहलू स्कोर · भार",
    "Sector tailwinds today": "आज सेक्टर का रुख", "macro drivers × sensitivity, + index momentum": "आर्थिक कारक × संवेदनशीलता, + इंडेक्स मोमेंटम",
    "National & international cues": "देश-विदेश के संकेत", "\"For India\" = this move's effect on Indian equities": "\"भारत पर असर\" = इस बदलाव का भारतीय शेयरों पर असर",
    "Sector indices vs Nifty": "निफ्टी की तुलना में सेक्टर इंडेक्स", "Nifty levels & movers": "निफ्टी स्तर और बड़े बदलाव", "Top gainers": "सबसे ज़्यादा बढ़े", "Top losers": "सबसे ज़्यादा गिरे",
    "News flow": "ख़बरों का प्रवाह", "Event radar · next 60 days": "इवेंट रडार · अगले 60 दिन", "India macro": "भारत के आर्थिक आंकड़े",
    "Global equities": "वैश्विक शेयर बाज़ार", "Asia": "एशिया", "Volatility": "उतार-चढ़ाव", "Rates & dollar": "ब्याज दर और डॉलर", "Rupee": "रुपया", "Gold": "सोना", "Domestic": "घरेलू",
    "Volatility & risk": "उतार-चढ़ाव और जोखिम", "Commodities": "कमोडिटी", "India": "भारत", "Breadth · Nifty 50": "बाज़ार की चौड़ाई · निफ्टी 50", "FII / DII (₹ cr)": "FII / DII (₹ करोड़)",
    "Nifty options": "निफ्टी ऑप्शंस", "Dollar index": "डॉलर इंडेक्स", "News sentiment": "ख़बरों की भावना", "Asian markets": "एशियाई बाज़ार", "Gold (safe haven)": "सोना (सुरक्षित निवेश)",
    "Copper (growth)": "तांबा (विकास)", "Market breadth (Nifty 50)": "बाज़ार की चौड़ाई (निफ्टी 50)", "Crude oil": "कच्चा तेल", "FII/DII flows (latest day)": "FII/DII प्रवाह (ताज़ा दिन)",
    "US volatility (VIX)": "अमेरिकी उतार-चढ़ाव (VIX)", "US bond yields": "अमेरिकी बॉन्ड यील्ड", "Nifty trend (technical)": "निफ्टी ट्रेंड (तकनीकी)",
    "NEUTRAL / MIXED": "तटस्थ / मिला-जुला", "Neutral / mixed": "तटस्थ / मिला-जुला", "RISK-ON": "तेज़ी का माहौल", "RISK-OFF": "डर का माहौल", "STRONG RISK-OFF": "भारी डर का माहौल", "STRONG RISK-ON": "ज़ोरदार तेज़ी का माहौल",
    "Brent crude": "ब्रेंट कच्चा तेल", "WTI crude": "WTI कच्चा तेल", "Natural gas": "प्राकृतिक गैस", "Silver": "चांदी", "Copper": "तांबा",
    "US risk appetite sets the tone for FII flows into emerging markets.": "अमेरिका में जोखिम लेने की इच्छा उभरते बाज़ारों में FII निवेश तय करती है।",
    "Tech sentiment — closely tracked by Indian IT.": "टेक सेक्टर का माहौल — भारतीय IT इस पर करीबी नज़र रखता है।",
    "US blue-chip mood.": "अमेरिकी बड़ी कंपनियों का मूड।", "European risk appetite.": "यूरोप में जोखिम लेने की इच्छा।", "European industrial cycle.": "यूरोप का औद्योगिक चक्र।",
    "Asia opens before India — sets the morning tone.": "एशिया भारत से पहले खुलता है — सुबह का रुख तय करता है।",
    "China/HK sentiment; competes with India for EM allocations.": "चीन/हांगकांग का माहौल; उभरते बाज़ारों के निवेश के लिए भारत से होड़।",
    "Chinese demand drives metals and commodities.": "चीन की मांग धातुओं और कमोडिटी को चलाती है।", "EM tech/export cycle.": "उभरते बाज़ारों का टेक/निर्यात चक्र।",
    "Rising US fear → global de-risking → FII selling in India.": "अमेरिका में बढ़ता डर → दुनिया भर में जोखिम घटाना → भारत में FII बिकवाली।",
    "Expected Nifty volatility over 30 days; spikes accompany sell-offs.": "अगले 30 दिनों में निफ्टी के उतार-चढ़ाव का अनुमान; उछाल अक्सर गिरावट के साथ आता है।",
    "Speculative risk appetite gauge.": "सट्टा जोखिम की इच्छा का पैमाना।", "Tracks Fed policy expectations.": "फेड नीति की उम्मीदों को दिखाता है।",
    "A strong dollar drains EM liquidity and weakens the rupee.": "मज़बूत डॉलर उभरते बाज़ारों से पैसा खींचता है और रुपये को कमज़ोर करता है।",
    "Euro-rupee — matters for European exporters.": "यूरो-रुपया — यूरोप को निर्यात करने वालों के लिए अहम।", "US oil benchmark.": "अमेरिकी तेल का बेंचमार्क।",
    "Input cost for city gas, fertiliser and power.": "सिटी गैस, खाद और बिजली की लागत।", "Safe-haven demand rises when investors are nervous.": "निवेशक घबराते हैं तो सुरक्षित निवेश की मांग बढ़ती है।",
    "Industrial + precious metal.": "औद्योगिक + कीमती धातु।", "\"Dr Copper\" — a global growth barometer; lifts metal stocks.": "\"डॉ. कॉपर\" — वैश्विक विकास का पैमाना; मेटल शेयरों को उठाता है।",
    "The benchmark.": "मुख्य बेंचमार्क।", "BSE benchmark.": "BSE का बेंचमार्क।", "Banks are ~1/3 of Nifty — the market rarely rallies without them.": "बैंक निफ्टी का ~1/3 हैं — उनके बिना बाज़ार शायद ही चढ़ता है।",
    "Risk appetite of domestic investors.": "घरेलू निवेशकों की जोखिम लेने की इच्छा।", "Retail speculation gauge.": "खुदरा सट्टे का पैमाना।",
    /* analyze a stock */
    "Intraday": "आज का सौदा (इंट्राडे)", "Swing · 2–6 weeks": "कुछ हफ़्तों का सौदा (2–6 हफ़्ते)", "Long-term · 6–18 months": "लंबा निवेश (6–18 महीने)", "Swing": "कुछ हफ़्तों का सौदा", "Long-term": "लंबा निवेश",
    "⚠ Before you trade": "⚠ सौदे से पहले ध्यान दें", "▲ What's working for it": "▲ इसके पक्ष में क्या है", "▼ What's against it": "▼ इसके ख़िलाफ़ क्या है",
    "Daily chart": "रोज़ का चार्ट", "last 180 sessions · hover for values": "पिछले 180 सत्र · मान देखने के लिए होवर करें", "Intraday · 5-minute": "आज का चार्ट (5 मिनट)",
    "plan levels drawn on the chart": "सौदे के भाव चार्ट पर दिखाए गए हैं", "Technical factors": "चार्ट के संकेत", "Fundamentals": "कंपनी की सेहत", "Backtests": "बैकटेस्ट", "Stock news": "शेयर की ख़बरें",
    "Market regime:": "बाज़ार का माहौल:", "Market regime": "बाज़ार का माहौल", "Daily trend": "दैनिक ट्रेंड", "Strength vs Nifty": "निफ्टी की तुलना में मज़बूती", "Chart setup": "चार्ट सेटअप",
    "Liquidity": "तरलता", "Daily range (ATR)": "दैनिक दायरा (ATR)", "Central Pivot Range width": "सेंट्रल पिवट रेंज चौड़ाई", "Sector tailwind": "सेक्टर का रुख", "Daily range": "दैनिक दायरा",
    "Turnover": "टर्नओवर", "vs Nifty 5d / 20d": "निफ्टी की तुलना 5दिन / 20दिन", "Yesterday high / low": "कल का ऊपरी / निचला", "Moving-average trend": "औसत भाव का रुझान",
    "ADX trend strength": "रुझान की ताकत (ADX)", "Price momentum (3m/6m)": "भाव की रफ़्तार (3 और 6 महीने)", "Fundamentals (value, quality, growth, health)": "कंपनी की सेहत (दाम, गुणवत्ता, विकास, कर्ज़)",
    "Long-term trend (DMAs, Supertrend, structure)": "लंबा रुझान (औसत भाव, सुपरट्रेंड, चार्ट की बनावट)", "Momentum & relative strength": "रफ़्तार और निफ्टी के मुकाबले प्रदर्शन",
    "Macro regime (global + India)": "देश-दुनिया का आर्थिक माहौल", "Daily trend:": "दैनिक ट्रेंड:", "Sector tailwind:": "सेक्टर का रुख:", "Chart setup:": "चार्ट सेटअप:", "News:": "ख़बरें:",
    "Universe": "शेयरों का समूह", "Your watchlist": "आपकी वॉचलिस्ट", "How Market Desk decides": "Market Desk कैसे फ़ैसला करता है",
    "Sources & limits": "स्रोत और सीमाएँ", "Before you trade": "ट्रेड से पहले",
    "Market Desk is a rule-based research tool, not investment advice. Signals are probabilities, not promises; past backtests do not guarantee future results. Always use a stop-loss and position sizes you can afford to lose. Data: Yahoo Finance, NSE, public RSS feeds.":
      "Market Desk नियमों पर आधारित एक रिसर्च टूल है, निवेश सलाह नहीं। सिग्नल संभावनाएँ हैं, वादे नहीं; पिछले बैकटेस्ट भविष्य की गारंटी नहीं देते। हमेशा स्टॉप-लॉस लगाएँ और उतनी ही रकम लगाएँ जिसका नुकसान आप सह सकें। डेटा: Yahoo Finance, NSE, सार्वजनिक RSS फ़ीड।",
    "Tailwinds dominate: favour long setups, buy dips in leaders, let winners run.": "माहौल अच्छा है: खरीद के सौदे चुनें, मज़बूत शेयरों में गिरावट पर खरीदें, मुनाफ़े वाले सौदे चलने दें।",
    "Mixed cues: be selective, trade smaller, prefer stocks with their own strength.": "मिले-जुले संकेत: सोच-समझकर चुनें, कम रकम लगाएँ, वही शेयर चुनें जो अपने दम पर मज़बूत हों।",
    "Headwinds dominate: protect capital, tighten stops, prefer defensives (FMCG, pharma, IT on a weak rupee) or cash; intraday shorts on weak stocks.": "माहौल ख़राब है: पूंजी बचाएँ, स्टॉप-लॉस पास रखें, रोज़मर्रा के सामान, दवा कंपनियाँ (और रुपया कमज़ोर हो तो IT) या नकद रखें; कमज़ोर शेयरों में ही शॉर्ट करें।",
    "Strong risk-on": "ज़ोरदार तेज़ी का माहौल", "Risk-on": "तेज़ी का माहौल", "Risk-off": "डर का माहौल", "Strong risk-off": "भारी डर का माहौल",
    "Not enough swings": "पर्याप्त आंकड़े नहीं", "Broke below recent swing lows (downtrend)": "हाल के निचले भाव टूट गए (मंदी का दौर)", "Broke above recent swing highs (uptrend)": "हाल के ऊँचे भाव पार हो गए (तेज़ी का दौर)",
    "Higher highs & higher lows (uptrend)": "हर उछाल और हर गिरावट पिछली से ऊँची (तेज़ी का दौर)", "Lower highs & lower lows (downtrend)": "हर उछाल और हर गिरावट पिछली से नीची (मंदी का दौर)",
    "Expanding range (volatile)": "दायरा फैल रहा (उतार-चढ़ाव बढ़ा)", "Contracting range (coiling)": "दायरा सिकुड़ रहा (बड़ी चाल से पहले का ठहराव)", "Nifty options (PCR)": "निफ्टी ऑप्शंस (PCR)",
    "Nifty valuation": "निफ्टी मूल्यांकन", "normal range": "सामान्य दायरा",
    "Settings": "सेटिंग्स", "⚡ Signals now": "⚡ अभी के सिग्नल", "Check every stock right now and show what to do at this moment": "अभी हर शेयर जाँचें और इस पल क्या करना है दिखाएँ", "Telegram": "टेलीग्राम", "Language of the Telegram alerts": "टेलीग्राम अलर्ट की भाषा", "Light / dark": "लाइट / डार्क", "Sign out of Market Desk": "Market Desk से साइन आउट",
    "Used to size every trade plan": "हर ट्रेड योजना का आकार इसी से तय होता है", "Most you are willing to lose on one trade, as % of capital": "एक ट्रेड में आप पूंजी का अधिकतम कितना % खोने को तैयार हैं",
    "Phone alerts through your Telegram bot": "आपके टेलीग्राम बॉट से फ़ोन अलर्ट", "Browser notification + sound when a signal fires": "सिग्नल आने पर ब्राउज़र सूचना + आवाज़",
    "Research the universe again and replace today's list": "सभी शेयरों पर दोबारा रिसर्च करके आज की सूची बदलें", "NSE symbol or company — e.g. RELIANCE, TCS, HDFCBANK": "NSE सिंबल या कंपनी — जैसे RELIANCE, TCS, HDFCBANK",
    /* session phases (from the server) */
    "Weekend — showing the last session": "सप्ताहांत — पिछला सत्र दिखा रहे हैं", "Pre-market — provisional watchlist from yesterday's data": "प्री-मार्केट — कल के डेटा से अस्थायी वॉचलिस्ट",
    "Opening range forming — list locks at 9:25 AM": "शुरुआती दायरा बन रहा है — सूची 9:25 बजे लॉक होगी", "Market open — live signals": "बाज़ार खुला — लाइव सिग्नल",
    "Market closed — today's results": "बाज़ार बंद — आज के नतीजे",
    /* analyze a stock — page labels */
    "Sell / short at": "बेचने का भाव (शॉर्ट)", "No trade plan — the edge isn't strong enough.": "सौदे की कोई योजना नहीं — संकेत काफ़ी मज़बूत नहीं।",
    "No intraday edge right now — wait for price to clear VWAP / the opening range with volume.": "अभी आज के सौदे का कोई अच्छा मौका नहीं — भाव के अच्छे कारोबार के साथ दिन के औसत भाव या पहले 15 मिनट के दायरे से बाहर निकलने का इंतज़ार करें।",
    "Trend and momentum are negative — avoid fresh longs; holders can exit or trail a tight stop.": "रुझान और रफ़्तार दोनों कमज़ोर — अभी न खरीदें; जिनके पास है वे निकल सकते हैं या स्टॉप-लॉस पास रखें।",
    "No swing entry — wait for the daily score to turn clearly positive.": "अभी कुछ हफ़्तों वाला सौदा नहीं — रोज़ के चार्ट का अंक साफ़ तौर पर अच्छा होने का इंतज़ार करें।",
    "No intraday data.": "आज के भाव की जानकारी नहीं मिली।", "Nothing strongly positive.": "कोई बड़ी अच्छी बात नहीं।", "Nothing strongly negative.": "कोई बड़ी बुरी बात नहीं।",
    "Session avg": "दिन का औसत भाव", "Bollinger %B": "आम दायरे में स्थिति (बोलिंजर)", "Stoch %K / %D": "स्टोकेस्टिक %K / %D", "Williams %R": "विलियम्स %R", "Indicators & levels": "चार्ट के आंकड़े और अहम भाव", "MACD / signal": "MACD / संकेत रेखा",
    "Volume vs 20d": "आज का कारोबार बनाम 20 दिन का औसत", "52-wk high / low": "साल का सबसे ऊँचा / नीचा भाव", "Beta vs Nifty": "निफ्टी के मुकाबले हलचल (बीटा)", "Volatility (ann.)": "सालाना उतार-चढ़ाव",
    "Max drawdown 1y": "साल में सबसे बड़ी गिरावट", "Return 1w": "1 हफ़्ते में बदलाव", "Return 1m": "1 महीने में बदलाव", "Return 3m": "3 महीने में बदलाव", "Return 6m": "6 महीने में बदलाव", "Return 1y": "1 साल में बदलाव",
    "Support / resistance zones": "सहारा और रुकावट के भाव", "Support": "सहारा (नीचे)", "Resistance": "रुकावट (ऊपर)", "dist": "दूरी", "touches": "कितनी बार छुआ", "Structure:": "चार्ट की बनावट:", "Candles:": "भाव के पैटर्न:",
    "Key numbers": "मुख्य आंकड़े", "Market cap": "कंपनी का कुल बाज़ार मूल्य", "P/E · fwd P/E": "कमाई के मुकाबले भाव (P/E) · अगले साल", "Op. / net margin": "कारोबारी / शुद्ध मुनाफ़ा दर",
    "Revenue / EPS growth": "बिक्री / प्रति शेयर कमाई में बढ़त", "Debt/Equity": "कर्ज़ बनाम अपनी पूंजी", "Promoter / inst.": "मालिक / बड़े निवेशक", "Analyst target": "विश्लेषकों का लक्ष्य", "Consensus": "आम राय",
    "Next results": "अगले नतीजे", "EPS ttm / fwd": "प्रति शेयर कमाई: पिछले 12 महीने / अगले साल", "About the business": "कारोबार के बारे में",
    "Yahoo Finance didn't return fundamentals for this symbol right now, so the long-term rating leans on trend, momentum, sector and macro only. Try ↻ later.": "अभी इस कंपनी के कमाई-कर्ज़ के आंकड़े नहीं मिले, इसलिए लंबे निवेश की राय सिर्फ़ रुझान, रफ़्तार, सेक्टर और आर्थिक माहौल पर है। थोड़ी देर बाद ↻ दबाएँ।",
    "Driver": "कारक", "Sensitivity": "असर कितना", "Move now": "अभी की चाल", "Effect": "असर", "Reading": "मतलब",
    "Backtest — same rules, this stock's history": "पुराने आंकड़ों पर जाँच — यही नियम, इसी शेयर पर", "Win rate": "जीत दर", "Avg trade": "औसत सौदा", "Avg win / loss": "औसत मुनाफ़ा / नुकसान",
    "Profit factor": "कुल मुनाफ़ा ÷ कुल नुकसान", "Total (compounded)": "कुल कमाई", "Max drawdown": "सबसे बड़ी गिरावट", "Buy & hold": "सिर्फ़ खरीदकर रखते तो", "Long / short wins": "खरीद / शॉर्ट में जीत",
    "Recent trades": "हाल के सौदे", "No trades.": "कोई सौदा नहीं।", "LONG": "खरीद", "SHORT": "शॉर्ट (पहले बेचा)", "Bollinger (20,2)": "भाव का आम दायरा (बोलिंजर)",
    "✔ These rules have worked on this stock — signals deserve more weight.": "✔ ये नियम इस शेयर पर पहले काम करते रहे हैं — इनके संकेत पर ज़्यादा भरोसा कर सकते हैं।",
    "✖ These rules have lost money on this stock — treat its signals with caution.": "✖ इन नियमों से इस शेयर पर पहले नुकसान हुआ है — इनके संकेत पर सावधानी रखें।",
    "● Mixed record — use the signals with other confirmation.": "● पुराना रिकॉर्ड मिला-जुला — संकेत को दूसरी बातों से भी परखें।",
    "Costs included (0.25% swing, 0.08% intraday). Past results do not guarantee future ones.": "खर्च जोड़कर (कुछ हफ़्तों वाले सौदे पर 0.25%, एक दिन वाले पर 0.08%)। पुराने नतीजे आगे की गारंटी नहीं।",
    "ACCUMULATE ON DIPS": "गिरावट पर थोड़ा-थोड़ा खरीदें", "REDUCE": "कुछ बेचें", "WATCH — LONG BIAS": "नज़र रखें — तेज़ी की ओर झुकाव", "WATCH — SHORT BIAS": "नज़र रखें — मंदी की ओर झुकाव",
    "WATCH — WEAK": "नज़र रखें — कमज़ोर", "NO CLEAR EDGE": "कोई साफ़ संकेत नहीं", "NO TRADE": "कोई सौदा नहीं",
    /* analyze a stock — factor names from the server */
    "Supertrend (10,3)": "सुपरट्रेंड रेखा", "Supertrend": "सुपरट्रेंड रेखा", "Relative strength vs Nifty": "निफ्टी के मुकाबले प्रदर्शन", "Volume: accumulation / distribution": "कारोबार: खरीदारी या बिकवाली का दबाव",
    "52-week range position": "साल भर के दायरे में भाव कहाँ है", "Money Flow Index": "पैसे का बहाव (MFI)", "Price vs VWAP": "भाव बनाम दिन का औसत भाव", "Price vs session average": "भाव बनाम दिन का औसत भाव",
    "EMA 9/21 crossover": "छोटा और बड़ा औसत (9/21)", "MACD histogram": "रुझान बदलाव संकेत (MACD)", "Opening-range breakout (15 min)": "पहले 15 मिनट के दायरे से बाहर निकलना",
    "Volume pressure (last 30 min)": "कारोबार का दबाव (पिछले 30 मिनट)", "Day structure (open / prev close)": "आज की चाल (खुलना / कल का बंद)", "Central Pivot Range": "पिवट दायरा (CPR)",
    "Intraday strength vs Nifty": "आज निफ्टी के मुकाबले प्रदर्शन",
    "P/E (trailing)": "कमाई के मुकाबले भाव (P/E)", "P/E vs sector norm": "कमाई के मुकाबले भाव (P/E)", "Forward vs trailing P/E": "अगले साल की कमाई पर P/E", "Price / Book": "बुक वैल्यू के मुकाबले भाव (P/B)", "PEG ratio": "विकास के मुकाबले भाव (PEG)",
    "Return on equity": "पूंजी पर कमाई (ROE)", "Operating margin": "कारोबारी मुनाफ़ा दर", "Net profit margin": "शुद्ध मुनाफ़ा दर", "Return on assets": "संपत्ति पर कमाई (ROA)",
    "Revenue growth (YoY)": "बिक्री में बढ़त (सालाना)", "Earnings growth (YoY)": "मुनाफ़े में बढ़त (सालाना)", "Debt / Equity": "कर्ज़ बनाम अपनी पूंजी", "Current ratio": "छोटे कर्ज़ चुकाने की क्षमता", "Free cash flow": "सारे खर्च के बाद बची नकदी",
    "Promoter / insider holding": "मालिकों (प्रमोटर) की हिस्सेदारी", "Institutional holding (FII+DII)": "बड़े निवेशकों की हिस्सेदारी (विदेशी + घरेलू संस्थाएँ)", "Analyst target upside": "विश्लेषकों के लक्ष्य तक बढ़त",
    "Analyst consensus": "विश्लेषकों की आम राय", "Dividend yield": "लाभांश (डिविडेंड) दर",
    "Value": "दाम", "Quality": "गुणवत्ता", "Growth": "विकास", "Health": "कर्ज़ और नकदी", "Ownership": "हिस्सेदारी", "Street": "विश्लेषकों की राय",
    "loss-making": "घाटे में", "positive": "हाँ, बचती है", "negative": "नहीं, कमी है",
    "buy": "खरीदें", "hold": "होल्ड", "sell": "बेचें", "strong_buy": "ज़ोरदार खरीद", "strong_sell": "ज़ोरदार बिक्री", "underperform": "कमज़ोर प्रदर्शन", "outperform": "बेहतर प्रदर्शन",
    /* analyze a stock — fixed notes from the server */
    "Not enough intraday bars yet.": "आज के भाव के अभी पर्याप्त आंकड़े नहीं।", "Still inside the opening range — no breakout yet.": "भाव अभी पहले 15 मिनट के दायरे के अंदर — कोई दिशा नहीं।",
    "Opening 15 minutes: spreads are wide and moves reverse often — wait for the opening range to form (9:30).": "पहले 15 मिनट: भाव तेज़ी से ऊपर-नीचे होता है और चाल अक्सर पलटती है — 9:30 बजे तक रुकें।",
    "After 2:30 PM: too little time left for a fresh intraday trade to work; manage open positions only.": "दोपहर 2:30 के बाद: नया सौदा न करें, सिर्फ़ खुले सौदे संभालें।",
    "Swing (daily score ≥ +0.30, 2-ATR stop, 4-ATR target)": "कुछ हफ़्तों का सौदा: अंक +0.30 पार करे तो खरीदें; स्टॉप-लॉस = रोज़ के औसत उतार-चढ़ाव का 2 गुना, लक्ष्य = 4 गुना",
    "Intraday (15-min score crosses ±0.45, 1.2-ATR stop, 2-ATR target, exit 3:15 PM)": "एक दिन का सौदा: 15 मिनट का अंक ±0.45 पार करे तो सौदा; स्टॉप-लॉस = औसत उतार-चढ़ाव का 1.2 गुना, लक्ष्य = 2 गुना, 3:15 बजे बंद",
    "No signals fired in the test window.": "जाँच के समय में कोई संकेत नहीं आया।",
    "Negative earnings — no P/E support.": "कंपनी घाटे में है — P/E का कोई मतलब नहीं।",
    "Forward P/E below trailing — analysts expect earnings to grow.": "अगले साल की अनुमानित कमाई पर P/E कम — कमाई बढ़ने की उम्मीद है।",
    "Forward P/E above trailing — earnings expected to shrink.": "अगले साल की अनुमानित कमाई पर P/E ज़्यादा — कमाई घटने का अनुमान है।",
    "Growth available cheaply (PEG < 1).": "विकास के हिसाब से सस्ता (PEG 1 से कम)।", "Paying a lot for the growth (PEG > 2.5).": "विकास के हिसाब से बहुत महँगा (PEG 2.5 से ज़्यादा)।",
    "Reasonable price for the growth.": "विकास के हिसाब से ठीक दाम।",
    "High ROE — efficient use of shareholder capital (a hallmark of Indian compounders).": "पूंजी पर कमाई ऊँची — कंपनी पैसे का अच्छा इस्तेमाल करती है (लंबे समय तक बढ़ने वाली कंपनियों की निशानी)।",
    "Low ROE — capital earns below its cost.": "पूंजी पर कमाई कम — लगाया पैसा अपनी लागत से कम कमा रहा है।", "Adequate ROE.": "पूंजी पर कमाई ठीक-ठाक।", "Loss-making.": "घाटे में।",
    "For banks RoA ≥ 1.2% is strong.": "बैंकों के लिए 1.2% या ज़्यादा अच्छा माना जाता है।", "Asset efficiency.": "संपत्ति से कितनी कमाई होती है।",
    "Strong top-line growth, ahead of nominal GDP.": "बिक्री तेज़ी से बढ़ रही है, देश की अर्थव्यवस्था से भी तेज़।", "Revenue shrinking.": "बिक्री घट रही है।",
    "Revenue growing slower than the economy.": "बिक्री अर्थव्यवस्था से धीमी बढ़ रही है।", "Growing roughly with the economy.": "बिक्री लगभग अर्थव्यवस्था की रफ़्तार से बढ़ रही है।",
    "Profits compounding fast — earnings drive long-term returns.": "मुनाफ़ा तेज़ी से बढ़ रहा है — लंबे समय में शेयर का भाव कमाई के साथ ही बढ़ता है।", "Profits falling.": "मुनाफ़ा घट रहा है।",
    "Profits barely growing.": "मुनाफ़ा लगभग नहीं बढ़ रहा।", "Moderate profit growth.": "मुनाफ़ा ठीक-ठाक बढ़ रहा है।",
    "Nearly debt-free — resilient to rate hikes.": "लगभग कोई कर्ज़ नहीं — ब्याज दर बढ़ने पर भी सुरक्षित।", "Highly leveraged — vulnerable to rising rates / slowdowns.": "बहुत ज़्यादा कर्ज़ — ब्याज दर बढ़ने या मंदी में ख़तरा।",
    "Meaningful debt — watch interest costs.": "ठीक-ठाक कर्ज़ — ब्याज के खर्च पर नज़र रखें।", "Manageable leverage.": "कर्ज़ संभालने लायक है।",
    "Short-term liabilities exceed short-term assets.": "जल्दी चुकाने वाले कर्ज़, जल्दी मिलने वाले पैसे से ज़्यादा हैं।", "Comfortable liquidity.": "रोज़मर्रा के खर्च के लिए पैसा काफ़ी है।",
    "Generates cash after capex — can fund growth/dividends itself.": "सारे खर्च के बाद भी नकदी बचती है — विकास और लाभांश अपने पैसे से कर सकती है।",
    "Burning cash — depends on borrowing or equity.": "खर्च कमाई से ज़्यादा — कर्ज़ लेकर या नए शेयर बेचकर पैसा जुटाना पड़ता है।",
    "High promoter skin in the game.": "मालिकों की बड़ी हिस्सेदारी — उनका अपना पैसा दांव पर है।", "Low promoter holding (common for professionally-run cos).": "मालिकों की हिस्सेदारी कम (पेशेवर प्रबंधन वाली कंपनियों में आम बात)।",
    "Moderate promoter holding.": "मालिकों की हिस्सेदारी ठीक-ठाक।", "Institutional sponsorship supports liquidity and re-rating.": "बड़े निवेशकों का भरोसा — शेयर की खरीद-बिक्री आसान रहती है।",
    "Scale 1 = strong buy … 5 = sell.": "पैमाना: 1 = ज़ोरदार खरीद … 5 = बेचें।", "Cash returned to shareholders.": "कंपनी शेयरधारकों को नकद लाभांश देती है।",
    "Existing holders can stay; fresh money should wait for trend confirmation (close above the 50-DMA with volume).": "जिनके पास है वे रखे रहें; नया पैसा तब लगाएँ जब भाव अच्छे कारोबार के साथ 50 दिन के औसत से ऊपर बंद हो।",
    "Trim on rallies; re-assess only after price reclaims the 200-DMA.": "तेज़ी आने पर कुछ बेच दें; भाव 200 दिन के औसत से ऊपर लौटे तभी दोबारा सोचें।",
    /* candlestick patterns */
    "Doji": "डोजी (अनिर्णय)", "Indecision; watch the next candle.": "खरीदार और बिकवाल बराबर; अगले दिन की चाल देखें।", "Hammer": "हैमर (नीचे से वापसी)", "Buyers rejected lower prices after a fall.": "गिरावट के बाद खरीदारों ने निचले भाव ठुकराए।",
    "Hanging man": "हैंगिंग मैन (ऊपर कमज़ोरी)", "Selling pressure appearing after a rise.": "तेज़ी के बाद बिकवाली का दबाव दिख रहा है।", "Shooting star": "शूटिंग स्टार (ऊपर से वापसी)",
    "Sellers rejected higher prices after a rise.": "तेज़ी के बाद बिकवालों ने ऊँचे भाव ठुकराए।", "Inverted hammer": "उलटा हैमर", "Early buying interest after a fall.": "गिरावट के बाद शुरुआती खरीद रुचि।",
    "Bullish engulfing": "तेज़ी वाली बड़ी कैंडल", "Buyers overwhelmed the prior down candle.": "खरीदारों ने पिछली गिरावट वाली कैंडल को पूरी तरह ढक लिया।", "Bearish engulfing": "मंदी वाली बड़ी कैंडल",
    "Sellers overwhelmed the prior up candle.": "बिकवालों ने पिछली बढ़त वाली कैंडल को पूरी तरह ढक लिया।", "Inside bar": "इनसाइड बार (ठहराव)",
    "Consolidation; a break of the mother bar sets direction.": "भाव ठहरा हुआ; पिछले दिन के ऊपर या नीचे निकलने से दिशा तय होगी।", "Bullish marubozu": "पूरी तेज़ी वाली कैंडल", "Bearish marubozu": "पूरी मंदी वाली कैंडल",
    "One side in control all session.": "पूरे सत्र एक ही पक्ष हावी रहा।", "Morning star": "मॉर्निंग स्टार (तेज़ी की वापसी)", "Three-candle bullish reversal.": "तीन कैंडल वाला तेज़ी का पलटाव।",
    "Evening star": "ईवनिंग स्टार (मंदी की वापसी)", "Three-candle bearish reversal.": "तीन कैंडल वाला मंदी का पलटाव।",
    /* sectors, their stories and the macro drivers */
    "IT services": "आईटी (सॉफ़्टवेयर) सेवाएँ", "Pharma": "दवा कंपनियाँ", "Private banks": "निजी बैंक", "PSU banks": "सरकारी बैंक", "NBFC / financials": "NBFC / वित्तीय", "Insurance": "बीमा", "Automobiles": "ऑटोमोबाइल",
    "Metals & mining": "धातु और खनन", "Oil & gas producers": "तेल और गैस उत्पादक", "Refiners / OMCs": "तेल रिफ़ाइनरी / पेट्रोल पंप कंपनियाँ", "Energy (diversified)": "ऊर्जा (विविध)",
    "Gas utilities": "गैस वितरण कंपनियाँ", "Power & utilities": "बिजली कंपनियाँ", "Real estate": "रियल एस्टेट", "Capital goods": "मशीनरी और भारी उपकरण", "Infrastructure": "इंफ्रास्ट्रक्चर", "Defence": "रक्षा",
    "Cement": "सीमेंट", "Chemicals": "केमिकल", "Paints": "पेंट", "Aviation": "एविएशन", "Telecom": "टेलीकॉम", "Consumer discretionary": "शौक़ के सामान (गैर-ज़रूरी)", "Jewellery": "ज्वेलरी",
    "Hospitals": "अस्पताल", "Media": "मीडिया", "Diversified": "विविध",
    "Earns in dollars: gains from a weaker rupee and strong US tech spending.": "डॉलर में कमाई: कमज़ोर रुपये और अमेरिका में मज़बूत टेक खर्च से फ़ायदा।",
    "Export-heavy and defensive: weaker rupee helps, holds up in sell-offs.": "निर्यात पर निर्भर और सुरक्षित: कमज़ोर रुपया मदद करता है, गिरावट में टिका रहता है।",
    "Biggest FII holding: sensitive to flows, rates and credit growth.": "FII की सबसे बड़ी हिस्सेदारी: निवेश प्रवाह, ब्याज दर और कर्ज़ वृद्धि के प्रति संवेदनशील।",
    "High-beta domestic cyclicals; bond-yield and asset-quality sensitive.": "ज़्यादा उतार-चढ़ाव वाले शेयर; ब्याज दर और डूबे कर्ज़ों से जल्दी प्रभावित।",
    "Borrow to lend: falling rates widen margins.": "उधार लेकर कर्ज़ देते हैं: गिरती ब्याज दरें मार्जिन बढ़ाती हैं।",
    "Long-duration businesses; like stable, falling yields.": "लंबी अवधि का कारोबार; स्थिर या गिरती ब्याज दरें इनके लिए अच्छी।",
    "Fuel prices hit demand; metal prices hit margins; rural income matters.": "ईंधन के दाम मांग पर, धातु के दाम मार्जिन पर असर डालते हैं; ग्रामीण आय अहम है।",
    "Defensive; crude-linked packaging costs; depends on rural demand & monsoon.": "सुरक्षित सेक्टर; पैकेजिंग लागत कच्चे तेल से जुड़ी; ग्रामीण मांग और मानसून पर निर्भर।",
    "Priced globally: China demand and the dollar decide.": "दाम वैश्विक स्तर पर तय: चीन की मांग और डॉलर फ़ैसला करते हैं।",
    "ONGC/Oil India realise more when crude rises.": "कच्चा तेल चढ़ने पर ONGC/Oil India को ज़्यादा दाम मिलते हैं।",
    "BPCL/HPCL/IOC: costlier crude squeezes marketing margins.": "BPCL/HPCL/IOC: महँगा कच्चा तेल मार्केटिंग मार्जिन दबाता है।",
    "Mixed exposure to oil, gas, retail, telecom.": "तेल, गैस, रिटेल, टेलीकॉम — मिला-जुला कारोबार।", "City-gas margins shrink when LNG costs rise.": "LNG महँगी होने पर सिटी-गैस मार्जिन घटते हैं।",
    "Capex-heavy, rate-sensitive, riding India’s power demand.": "भारी निवेश वाला कारोबार, ब्याज दर से प्रभावित, भारत की बढ़ती बिजली मांग से फ़ायदा।",
    "Most rate-sensitive sector: home-loan rates drive demand.": "ब्याज दर के प्रति सबसे संवेदनशील सेक्टर: होम-लोन दरें मांग तय करती हैं।",
    "Government & private capex cycle.": "सरकार और कंपनियों के नए निवेश (कारख़ाने, मशीनें) पर निर्भर।", "Order books tied to government spending; bitumen/fuel costs.": "काम सरकारी खर्च पर निर्भर; डामर और ईंधन की लागत।",
    "Government orders & indigenisation; geopolitics can lift sentiment.": "सरकारी ऑर्डर और स्वदेशीकरण; भू-राजनीति माहौल सुधार सकती है।",
    "Energy is ~30% of cost (pet coke, diesel).": "लागत का ~30% ऊर्जा है (पेट कोक, डीज़ल)।", "Crude-derived inputs; export-oriented; China competition.": "कच्चे तेल से बने कच्चे माल; निर्यात पर ज़ोर; चीन से होड़।",
    "~50% of raw materials are crude derivatives.": "~50% कच्चा माल कच्चे तेल से बनता है।", "Jet fuel is the biggest cost; leases are in dollars.": "जेट ईंधन सबसे बड़ी लागत; लीज़ डॉलर में।",
    "Defensive cash flows; tariff hikes drive earnings.": "स्थिर नकदी प्रवाह; टैरिफ़ बढ़ोतरी से कमाई बढ़ती है।", "Urban spending, festive demand, inflation.": "शहरी खर्च, त्योहारी मांग, महंगाई।",
    "Gold price vs wedding demand.": "सोने का भाव बनाम शादियों की मांग।", "Defensive, structural growth.": "सुरक्षित, लंबी अवधि की वृद्धि।", "Ad-spend cycle.": "विज्ञापन खर्च का चक्र।",
    "Moves with the broad market.": "पूरे बाज़ार के साथ चलता है।",
    "Global stocks": "वैश्विक शेयर", "US VIX": "अमेरिका में डर का पैमाना", "US yields": "अमेरिकी ब्याज दरें", "Dollar": "डॉलर", "Crude": "कच्चा तेल", "Nat gas": "प्राकृतिक गैस", "US short rates": "अमेरिकी अल्पकालिक दरें",
    /* news topics */
    "RBI & rates": "RBI और ब्याज दर", "US Fed": "अमेरिकी फ़ेड", "Inflation": "महंगाई", "Rupee & FX": "रुपया और विदेशी मुद्रा", "FII / DII flows": "FII / DII प्रवाह", "Earnings": "नतीजे",
    "Geopolitics": "भू-राजनीति", "Trade & tariffs": "व्यापार और टैरिफ़", "Govt policy & budget": "सरकारी नीति और बजट", "Monsoon & rural": "मानसून और ग्रामीण", "Regulation (SEBI)": "नियमन (SEBI)",
    "IPOs": "IPO", "Elections": "चुनाव",
    /* plain-Hindi names for chart terms */
    "20-DMA": "20 दिन का औसत",
    "50-DMA": "50 दिन का औसत",
    "200-DMA": "200 दिन का औसत",
    "VWAP": "दिन का औसत भाव",
    "EMA 9": "9 कैंडल का औसत",
    "EMA 21": "21 कैंडल का औसत",
    "RSI 14": "तेज़ी-मंदी पैमाना (RSI)",
    "MACD (12,26,9)": "रुझान बदलाव संकेत (MACD)",
    "RSI (14)": "तेज़ी-मंदी पैमाना (RSI)",
    "20 / 50 / 200 DMA": "20 / 50 / 200 दिन का औसत",
    "P/B · PEG": "बुक वैल्यू से भाव (P/B) · विकास से भाव (PEG)",
    "EV/EBITDA": "कंपनी मूल्य ÷ कारोबारी कमाई",
    "ROE · ROA": "पूंजी पर कमाई · संपत्ति पर कमाई",
    "FMCG": "रोज़मर्रा का सामान (FMCG)",
    "USD/INR": "डॉलर के मुकाबले रुपया (USD/INR)",
    "India VIX": "डर का पैमाना (India VIX)",
  };
  /* sector-norm words used inside the P/E note */
  const NORM_HI = { "IT services": "आईटी सेवाएँ", "pharma": "फार्मा", "private bank": "निजी बैंक", "PSU bank": "सरकारी बैंक", "NBFC/financials": "NBFC/वित्तीय", "insurance": "बीमा", "auto": "ऑटो",
    "FMCG": "FMCG", "metals": "धातु", "upstream oil": "तेल उत्पादन", "refining/marketing": "रिफ़ाइनिंग/मार्केटिंग", "energy": "ऊर्जा", "power utility": "बिजली कंपनी", "real estate": "रियल एस्टेट",
    "capital goods": "कैपिटल गुड्स", "cement": "सीमेंट", "chemicals": "केमिकल", "paints": "पेंट", "aviation": "एविएशन", "telecom": "टेलीकॉम", "consumer discretionary": "उपभोक्ता (गैर-ज़रूरी)",
    "jewellery": "ज्वेलरी", "hospitals": "अस्पताल", "media": "मीडिया", "defence": "रक्षा", "infrastructure": "इंफ्रास्ट्रक्चर", "market": "बाज़ार" };
  const PRICE_BITS = { "above 50-DMA": "50 दिन के औसत से ऊपर", "below 50-DMA": "50 दिन के औसत से नीचे", "above 200-DMA": "200 दिन के औसत से ऊपर", "below 200-DMA": "200 दिन के औसत से नीचे",
    "50>200 (golden-cross regime)": "50 दिन का औसत 200 दिन वाले से ऊपर (मज़बूत दौर)", "50<200 (death-cross regime)": "50 दिन का औसत 200 दिन वाले से नीचे (कमज़ोर दौर)",
    "50-DMA rising": "50 दिन का औसत चढ़ रहा", "50-DMA falling": "50 दिन का औसत गिर रहा" };
  const UPDN = { above: "ऊपर", below: "नीचे", Above: "ऊपर", Below: "नीचे", rising: "बढ़ रहा", falling: "घट रहा", positive: "सकारात्मक", negative: "नकारात्मक" };
  const hx = s => Object.prototype.hasOwnProperty.call(HI, s) ? HI[s] : s;
  const whyHi = w => { const m = w.match(/^(.+) (up|down) (helps|hurts)$/); return m ? hx(m[1]) + (m[2] === "up" ? " ऊपर" : " नीचे") + (m[3] === "helps" ? " (मदद)" : " (नुकसान)") : w; };

  /* phrases with numbers: full-text patterns */
  const HI_RX = [
    [/^weight (\d+)%$/, "महत्व $1%"], [/^· weight (\d+)%$/, "· महत्व $1%"], [/^(.+) · weight (\d+)%$/, (m, a, w) => trx(a) + " · महत्व " + w + "%"],
    /* analyze a stock — factor values */
    [/^([+-]?[\d.]+)% vs (50|200)-DMA$/, "$2 दिन के औसत से $1%"], [/^([\d.]+)x up\/down vol$/, "तेज़ी/मंदी कारोबार $1 गुना"], [/^(\d+)% of range$/, "साल के दायरे का $1%"],
    [/^([+-]?[\d.]+)% \(3m\)$/, "$1% (3 महीने)"], [/^([+-][\d.]+) pts$/, "$1 अंक"], [/^([\d.]+)x \(norm ~([\d.]+)x\)$/, "$1 गुना (आम ~$2)"], [/^([\d.]+)x fwd$/, "$1 गुना (अगले साल)"],
    [/^([\d.]+)x$/, "$1 गुना"], [/^([\d.]+) \(([a-z_]+|—)\)$/, (m, a, k) => a + " (" + hx(k) + ")"],
    /* analyze a stock — daily chart notes */
    [/^Price (.+)\.$/, (m, b) => { const bits = b.split(", "); return bits.every(x => PRICE_BITS[x]) ? "भाव " + bits.map(x => PRICE_BITS[x]).join(", ") + "।" : m; }],
    [/^ADX (\d+): no real trend.*$/, "ADX $1: कोई साफ़ रुझान नहीं — भाव एक दायरे में घूम रहा है, संकेत कम भरोसेमंद।"],
    [/^ADX (\d+) with \+DI above -DI.*$/, "ADX $1: खरीदार रुझान चला रहे हैं।"], [/^ADX (\d+) with -DI above \+DI.*$/, "ADX $1: बिकवाल रुझान चला रहे हैं।"],
    [/^In buy mode; trailing support at ([\d.]+)\.$/, "सुपरट्रेंड खरीद की ओर; नीचे सहारा ₹$1 पर।"], [/^In sell mode; overhead resistance at ([\d.]+)\.$/, "सुपरट्रेंड बिक्री की ओर; ऊपर रुकावट ₹$1 पर।"],
    [/^RSI (\d+) — bullish momentum zone.*$/, "RSI $1 — खरीदारों का ज़ोर।"], [/^RSI (\d+) — bearish momentum zone.*$/, "RSI $1 — बिकवालों का ज़ोर।"],
    [/^RSI (\d+) — overbought.*$/, "RSI $1 — बहुत ज़्यादा चढ़ चुका: मज़बूत है, पर अभी महँगा; पीछे भागने की बजाय गिरावट पर खरीदें।"],
    [/^RSI (\d+) — oversold.*$/, "RSI $1 — बहुत ज़्यादा गिर चुका: कमज़ोर है, पर थोड़ा उछाल आ सकता है।"],
    [/^RSI (\d+) — exhausted, avoid fresh longs.*$/, "RSI $1 — बहुत चढ़ चुका, अभी नई खरीद न करें।"], [/^RSI (\d+) — exhausted, avoid fresh shorts.*$/, "RSI $1 — बहुत गिर चुका, अभी नया शॉर्ट न करें।"],
    [/^RSI (\d+) — bullish\.$/, "RSI $1 — तेज़ी।"], [/^RSI (\d+) — bearish\.$/, "RSI $1 — मंदी।"],
    [/^Histogram (positive|negative)(?: and|,) (rising|falling)(?:; MACD line (above|below) zero)?\.$/, (m, a, b, c) => "MACD " + (a === "positive" ? "तेज़ी" : "मंदी") + " की ओर, " +
      (b === "rising" ? "सुधर रहा" : "कमज़ोर हो रहा") + (c ? "; मुख्य रेखा शून्य से " + UPDN[c] : "") + "।"],
    [/^3-month ([+-][\d.]+%)(?:, 6-month ([+-][\d.]+%))?\. Stocks with strong.*$/,
      (m, a, b) => "3 महीने में " + a + (b ? ", 6 महीने में " + b : "") + "। जिन शेयरों की 3–12 महीने की रफ़्तार अच्छी होती है, वे अक्सर आगे भी अच्छा करते हैं।"],
    [/^(Outperformed|Underperformed) Nifty 50 by ([\d.]+) pts over 3 months\.$/, (m, a, b) => "पिछले 3 महीने में निफ्टी 50 से " + b + " अंक " + (a === "Outperformed" ? "बेहतर" : "कमज़ोर") + " रहा।"],
    [/^Up-day volume is ([\d.]+)x down-day volume over 20 sessions; OBV (rising|falling)[^.]*\.(?: Today ([\d.]+)x average volume\.)?$/,
      (m, a, o, t) => "पिछले 20 दिनों में तेज़ी वाले दिनों का कारोबार मंदी वाले दिनों का " + a + " गुना; " + (o === "rising" ? "खरीदारी बढ़ रही है" : "बिकवाली बढ़ रही है") + "।" + (t ? " आज का कारोबार सामान्य का " + t + " गुना।" : "")],
    [/^([\d.]+)% below the 52-week high \(([\d.]+)\); (near highs|near lows|mid-range).*$/, (m, a, h, k) => "साल के सबसे ऊँचे भाव (₹" + h + ") से " + a + "% नीचे; " +
      ({ "near highs": "ऊँचाई के पास — ऊपर बिकवाली का दबाव कम।", "near lows": "साल के निचले स्तर के पास — अभी गिरावट का दौर।", "mid-range": "दायरे के बीच में।" }[k])],
    [/^MFI (\d+) — volume-weighted buying (exceeds|trails) selling.*$/, (m, a, b) => "MFI " + a + " — " + (b === "exceeds" ? "पैसा आ रहा है (खरीदारी ज़्यादा)।" : "पैसा जा रहा है (बिकवाली ज़्यादा)।")],
    [/^MFI (\d+) — overbought.*$/, "MFI $1 — बहुत ज़्यादा खरीदारी हो चुकी।"], [/^MFI (\d+) — oversold.*$/, "MFI $1 — बहुत ज़्यादा बिकवाली हो चुकी।"],
    /* analyze a stock — intraday notes */
    [/^(Above|Below) (?:VWAP|average price) ([\d.]+) — intraday buyers are (in profit|under water).*$/,
      (m, a, v, c) => "भाव दिन के औसत भाव (₹" + v + ") से " + UPDN[a] + " — " + (c === "in profit" ? "आज खरीदने वाले ज़्यादातर मुनाफ़े में हैं, गिरावट पर खरीदारी आती है।" : "आज खरीदने वाले ज़्यादातर घाटे में हैं, तेज़ी पर बिकवाली आती है।")],
    [/^EMA9 (above|below) EMA21, price (above|below) EMA9\.$/, (m, a, b) => "छोटा औसत (9) बड़े औसत (21) से " + UPDN[a] + ", भाव छोटे औसत से " + UPDN[b] + "।"],
    [/^(Buy|Sell) mode, trailing stop ([\d.]+)\.$/, (m, a, b) => "सुपरट्रेंड " + (a === "Buy" ? "खरीद" : "बिक्री") + " की ओर, स्टॉप-लॉस ₹" + b + "।"],
    [/^Broke (above|below) the opening range (?:high|low) ([\d.]+)\.$/, (m, a, b) => "भाव पहले 15 मिनट के " + (a === "above" ? "ऊपरी" : "निचले") + " स्तर ₹" + b + " से " + UPDN[a] + " निकल गया।"],
    [/^(Buyers|Sellers) in control: volume is concentrated on (?:up|down)-closes \(([+-][\d.]+)\)(?:; last bar ([\d.]+)x normal volume)?\.$/,
      (m, a, p, v) => (a === "Buyers" ? "खरीदार हावी: कारोबार ज़्यादातर चढ़कर बंद होने वाली कैंडलों में" : "बिकवाल हावी: कारोबार ज़्यादातर गिरकर बंद होने वाली कैंडलों में") + " (" + p + ")" + (v ? "; आखिरी कैंडल में सामान्य से " + v + " गुना कारोबार" : "") + "।"],
    [/^Gap ([+-][\d.]+%), now ([+-][\d.]+%) on the day, (above|below) the open( — gap-up being sold\.| — gap-down being bought\.|\.)$/,
      (m, g, c, a, t) => "दिन की शुरुआत " + g + " के अंतर से, अभी दिन में " + c + ", खुलने के भाव से " + UPDN[a] + (t === "." ? "।" : t.indexOf("gap-up") >= 0 ? " — ऊँची शुरुआत के बाद बिकवाली।" : " — नीची शुरुआत के बाद खरीदारी।")],
    [/^(Above|Below|Inside) CPR — [^.]*\. CPR width ([\d.]+)%( \(narrow[^)]*\)\.|\.)$/,
      (m, a, w, t) => ({ Above: "भाव पिवट दायरे (CPR) से ऊपर — आज तेज़ी का झुकाव।", Below: "भाव पिवट दायरे (CPR) से नीचे — आज मंदी का झुकाव।", Inside: "भाव पिवट दायरे के अंदर — दिशा तय नहीं।" }[a]) +
        " दायरे की चौड़ाई " + w + "%" + (t === "." ? "।" : " (संकरा → आज बड़ी चाल संभव)।")],
    [/^(Outperforming|Underperforming) Nifty by ([\d.]+) pts today\.$/, (m, a, b) => "आज निफ्टी से " + b + " अंक " + (a === "Outperforming" ? "बेहतर" : "कमज़ोर") + "।"],
    [/^Daily trend score ([+-][\d.]+) supports (longs|shorts)\.$/, (m, a, b) => "रोज़ के चार्ट का रुझान (" + a + ") " + (b === "longs" ? "खरीद" : "शॉर्ट") + " के पक्ष में।"],
    [/^Market regime ([+-][\d.]+) \((risk-on|risk-off|neutral)\) — .*$/,
      (m, a, b) => "बाज़ार का माहौल " + a + " (" + { "risk-on": "तेज़ी का) — खरीद में मदद।", "risk-off": "डर का) — शॉर्ट में मदद।", neutral: "मिला-जुला) — किसी तरफ़ मदद नहीं।" }[b]],
    [/^15-minute score ([+-][\d.]+) (agrees|disagrees) with 5-minute.*$/, (m, a, b) => "15 मिनट वाला चार्ट (" + a + ") 5 मिनट वाले से " + (b === "agrees" ? "सहमत।" : "असहमत — भरोसा कम।")],
    [/^India VIX ([\d.]+) is elevated.*$/, "बाज़ार में डर ज़्यादा है (India VIX $1) — आधी रकम लगाएँ, बड़े उतार-चढ़ाव के लिए तैयार रहें।"],
    [/^Results due (.+) — event risk.*$/, "कंपनी के नतीजे $1 को — उस दिन भाव अचानक उछल या गिर सकता है।"],
    [/^Relative volume ([\d.]+)x — thin participation.*$/, "आज कारोबार कम है (सामान्य का $1 गुना) — भाव का दायरा तोड़ना कम भरोसेमंद।"],
    /* analyze a stock — company notes */
    [/^Trades at (\d+)% of the typical (.+) multiple — (cheap vs peers|premium valuation; growth must deliver|fairly valued)\.$/,
      (m, p, k, t) => "कमाई के मुकाबले भाव, " + (NORM_HI[k] || k) + " सेक्टर के आम स्तर का " + p + "% — " + ({ "cheap vs peers": "दूसरों से सस्ता।", "fairly valued": "सही दाम।" }[t] || "महँगा; आगे कमाई तेज़ी से बढ़नी चाहिए।")],
    [/^For lenders P\/B is the key valuation — norm ~([\d.]+)x\.$/, "बैंक और कर्ज़ देने वाली कंपनियों में यही मुख्य पैमाना है — आम स्तर ~$1 गुना।"], [/^Norm for the sector ~([\d.]+)x\.$/, "इस सेक्टर का आम स्तर ~$1 गुना।"],
    [/^(Above|Below) the sector norm of ~(\d+)%\.$/, (m, a, b) => "सेक्टर के आम स्तर (~" + b + "%) से " + (a === "Above" ? "ज़्यादा" : "कम") + "।"],
    [/^Keeps ([\d.]+)p of every ₹1 of sales\.$/, "हर ₹1 की बिक्री पर $1 पैसे शुद्ध मुनाफ़ा।"],
    [/^Mean target (₹[\d.]+)(?: from (\d+) analysts)?\.$/, (m, a, n) => "विश्लेषकों का औसत लक्ष्य " + a + (n ? " (" + n + " विश्लेषक)" : "") + "।"],
    /* analyze a stock — long-term verdict and sector */
    [/^Sector tailwind \((.+)\)$/, (m, a) => "सेक्टर का साथ (" + hx(a) + ")"],
    [/^Sector (tailwind|headwind): (.+?)(?: — (.+))?\.$/, (m, a, l, w) => (a === "tailwind" ? "सेक्टर का साथ: " : "सेक्टर से नुकसान: ") + hx(l) + (w ? " — " + w.split("; ").map(whyHi).join("; ") : "") + "।"],
    [/^Stretched \(RSI > 70\): stagger buys — 1\/3 now, add near (₹[\d.,]+).*$/, "बहुत चढ़ चुका (RSI 70 से ऊपर): एक साथ न खरीदें — अभी 1/3, बाकी $1 के पास या दोबारा ऊपर निकलने पर।"],
    [/^Buy in 2–3 tranches over the next few weeks; add on dips toward (₹[\d.,]+)\.$/, "अगले कुछ हफ़्तों में 2–3 हिस्सों में खरीदें; $1 की ओर गिरावट आए तो और खरीदें।"],
    [/^(Benefits|Hurt) when (.+) rises(?:; it is currently (rising|falling|flat))?\.$/, (m, a, d, s) => hx(d) + " चढ़े तो " + (a === "Benefits" ? "फ़ायदा" : "नुकसान") +
      (s ? "; अभी यह " + { rising: "चढ़ रहा है", falling: "गिर रहा है", flat: "स्थिर है" }[s] : "") + "।"],
    [/^Updated (.+) IST · next refresh in (\d+)s$/, "अपडेट: $1 IST · अगला रिफ़्रेश $2 सेकंड में"], [/^Updated (.+) IST$/, "अपडेट: $1 IST"],
    [/^Updated (.+) IST \(cached\) · prices from (.+)$/, "अपडेट: $1 IST (कैश) · भाव स्रोत: $2"],
    [/^up to (\d+)$/, "अधिकतम $1"], [/^(\d+) shares$/, "$1 शेयर"],
    [/^short if below (₹[\d,.]+)$/, "$1 से नीचे हो तो शॉर्ट करें"], [/^buy if above (₹[\d,.]+)$/, "$1 से ऊपर हो तो खरीदें"],
    [/^up to (₹[\d,]+) per stock · max (\d+) at once$/, "हर शेयर में अधिकतम $1 · एक साथ अधिकतम $2"],
    [/^(\d+) closed · (\d+) open · after charges$/, "$1 बंद · $2 खुले · चार्ज के बाद"],
    [/^(\d+) new signals?$/, "$1 नया सिग्नल"], [/^(\d+) open trades?$/, "$1 खुला ट्रेड"],
    [/^in (\d+)d$/, "$1 दिन में"], [/^MOVE STOP-LOSS to (₹[\d,.]+)$/, "स्टॉप-लॉस $1 पर ले जाएँ"],
    [/^Plan: BUY only if price goes above (₹[\d,.]+) after 9:30 AM\.(.*)$/, "योजना: 9:30 बजे के बाद भाव $1 से ऊपर जाए तभी खरीदें।$2"],
    [/^Plan: BUY only if price goes above (₹[\d,.]+), and the page confirms it\.(.*)$/, "योजना: भाव $1 से ऊपर जाए और पेज पुष्टि करे, तभी खरीदें।$2"],
    [/^Plan: SHORT \(sell first, buy back later today\) only if price falls below (₹[\d,.]+) after 9:30 AM\.(.*)$/, "योजना: 9:30 बजे के बाद भाव $1 से नीचे जाए तभी शॉर्ट करें (पहले बेचें, आज ही वापस खरीदें)।$2"],
    [/^Plan: SHORT \(sell first, buy back later today\) only if price falls below (₹[\d,.]+), and the page confirms it\.(.*)$/, "योजना: भाव $1 से नीचे जाए और पेज पुष्टि करे, तभी शॉर्ट करें (पहले बेचें, आज ही वापस खरीदें)।$2"],
    [/^Bought (\d+) shares at (₹[\d,.]+) \((\d\d:\d\d)\) — (₹[\d,.]+)$/, "$1 शेयर $2 पर खरीदे ($3) — $4"],
    [/^Sold \(short\) (\d+) shares at (₹[\d,.]+) \((\d\d:\d\d)\) — (₹[\d,.]+)$/, "$1 शेयर $2 पर शॉर्ट किए ($3) — $4"],
    [/^Preview — what you would buy on (.+)$/, "झलक — $1 को आप क्या खरीदेंगे"], [/^BUY on (.+)$/, "$1 को खरीदें"],
    [/^(\d+) stocks × ~(₹[\d,]+) of your (₹[\d,]+)$/, "$1 शेयर × ~$2 (आपके $3 में से)"], [/^set on (.+)$/, "$1 को तय"],
    [/^Nifty ([\d,.]+) vs 200-day avg ([\d,.]+) \((.+)\)$/, "निफ्टी $1 बनाम 200-दिन औसत $2 ($3)"],
    [/^positive \((.+)\)$/, "सकारात्मक ($1)"], [/^negative \((.+)\)$/, "नकारात्मक ($1)"], [/^neutral \((.+)\)$/, "तटस्थ ($1)"],
    [/^(.+) · above 50-DMA$/, "$1 · 50-DMA से ऊपर"], [/^(.+) · below 50-DMA$/, "$1 · 50-DMA से नीचे"],
    [/^Next session watchlist \((.+)\) — provisional, locks at 9:25 AM$/, "अगले सत्र की वॉचलिस्ट ($1) — अस्थायी, 9:25 बजे लॉक होगी"],
    [/^NSE (open|closed|Open|Closed|Pre-market|Post-market|Weekend|Holiday) · (.+) IST$/, (m, a, b) => "NSE " + ({ open: "खुला", closed: "बंद", Open: "खुला", Closed: "बंद", "Pre-market": "प्री-मार्केट", "Post-market": "बाज़ार के बाद", Weekend: "सप्ताहांत", Holiday: "छुट्टी" }[a] || a) + " · " + b + " IST"],
    [/^Nifty valuation \(P\/E ([\d.]+)\)$/, "निफ्टी मूल्यांकन (P/E $1)"], [/^(\d+)% above 50-DMA · (\d+)% above 20-DMA$/, "$1% 50-DMA से ऊपर · $2% 20-DMA से ऊपर"],
    [/^Updated (.+)$/, "अपडेट: $1"],
  ];
  /* small words inside longer strings */
  const SUBS = [
    [/ · sentiment /g, " · भावना "], [/^sentiment /, "भावना "], [/ min ago/g, " मिनट पहले"], [/(^| · )just now(?= · |$)/g, "$1अभी-अभी"], [/ h ago/g, " घंटे पहले"], [/ d ago/g, " दिन पहले"],
    [/ · pick #(\d+)/g, " · चयन #$1"], [/ scored headlines/g, " आंकी गई ख़बरें"],
  ];

  const orig = new WeakMap();
  const tr = s => {
    const t = s.trim(); if (!t) return null;
    if (Object.prototype.hasOwnProperty.call(HI, t)) return s.replace(t, HI[t]);
    for (const [rx, rep] of HI_RX) if (rx.test(t)) { const o = t.replace(rx, rep); if (o !== t) return s.replace(t, o); }
    let o = t, hit = false; for (const [rx, rep] of SUBS) if (rx.test(o)) { o = o.replace(rx, rep); hit = true; }
    if (hit) { const c = parts(o); return s.replace(t, c == null ? o : c); }
    const c = parts(t); return c == null ? null : s.replace(t, c);
  };
  const trx = s => { const r = tr(s); return r == null ? s : r; };
  /* Lines the server builds from known pieces: "a · b · c", "Label: note", "A, B, C".
     Translate the pieces; give up (null) when nothing in them is known. */
  function parts(t) {
    if (t.indexOf(" · ") > 0) {
      let hit = false; const out = t.split(" · ").map(p => { const r = tr(p); if (r != null && r !== p) hit = true; return r == null ? p : r; });
      if (hit) return out.join(" · ");
    }
    for (let i = t.indexOf(": "); i > 0; i = t.indexOf(": ", i + 2)) {
      const a = t.slice(0, i), b = t.slice(i + 2);
      if (Object.prototype.hasOwnProperty.call(HI, a)) return HI[a] + ": " + trx(b);
    }
    for (let i = t.indexOf(": "); i > 0; i = t.indexOf(": ", i + 2)) { const b = tr(t.slice(i + 2)); if (b != null) return trx(t.slice(0, i)) + ": " + b; }
    if (t.indexOf(", ") > 0) { const items = t.split(", ").map(x => tr(x)); if (items.every(x => x != null)) return items.join(", "); }
    return null;
  }
  const ATTRS = ["title", "placeholder", "aria-label", "data-l"];
  function apply(root) {
    if (!root) return;
    if (root.nodeType === 3) { one(root); return; }
    if (root.nodeType !== 1 || root.closest && root.closest("script,style,[data-noi18n]")) return;
    const w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    let n; const list = []; while ((n = w.nextNode())) list.push(n);
    list.forEach(one);
    const els = root.querySelectorAll ? [root, ...root.querySelectorAll("[title],[placeholder],[aria-label],[data-l]")] : [root];
    els.forEach(el => ATTRS.forEach(a => {
      if (!el.hasAttribute || !el.hasAttribute(a)) return;
      const k = "i18nEn" + a.replace(/[^a-z]/g, "");
      if (el.dataset[k] === undefined) el.dataset[k] = el.getAttribute(a);
      const en = el.dataset[k]; const v = lang === "hi" ? (tr(en) ?? en) : en;
      if (el.getAttribute(a) !== v) el.setAttribute(a, v);
    }));
  }
  function one(n) {
    const p = n.parentElement; if (!p || /^(SCRIPT|STYLE|TEXTAREA)$/.test(p.tagName) || p.closest("[data-noi18n]")) return;
    const cur = n.nodeValue; let en = orig.get(n);
    if (en === undefined || (cur !== en && cur !== tr(en))) { en = cur; orig.set(n, en); } // new text written by the app
    const v = lang === "hi" ? (tr(en) ?? en) : en;
    if (cur !== v) n.nodeValue = v;
  }
  const obs = new MutationObserver(ms => { for (const m of ms) { if (m.type === "characterData") one(m.target); else m.addedNodes.forEach(apply); if (m.type === "attributes") apply(m.target); } });
  function start() {
    document.documentElement.lang = lang === "hi" ? "hi" : "en";
    apply(document.body);
    obs.observe(document.body, { childList: true, subtree: true, characterData: true });
  }
  const listeners = [];
  window.I18N = {
    get lang() { return lang; },
    set(l) {
      lang = l === "hi" ? "hi" : "en"; try { localStorage.setItem(KEY, lang); } catch (e) {}
      document.documentElement.lang = lang;
      apply(document.body); listeners.forEach(f => { try { f(lang); } catch (e) {} });
    },
    onChange(f) { listeners.push(f); },
    t(s) { return lang === "hi" ? (tr(s) ?? s) : s; },
  };
  window.L = (en, hi) => lang === "hi" ? hi : en;
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start); else start();
})();
