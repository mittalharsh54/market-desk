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
    "Capital ₹": "पूंजी ₹", "Risk / trade %": "जोखिम / ट्रेड %", "Trades": "ट्रेड", "Buy & short": "खरीद और शॉर्ट", "Buy only": "सिर्फ़ खरीद", "Leverage": "लीवरेज", "Language": "भाषा",
    "Core portfolio ✓ tested": "कोर पोर्टफ़ोलियो ✓ परखा हुआ", "Paper track record": "पेपर ट्रैक रिकॉर्ड",
    "Paper track record · real prices · after charges · no money invested": "पेपर ट्रैक रिकॉर्ड · असली भाव · चार्ज के बाद · कोई पैसा नहीं लगाया", "Core portfolio · ETFs · delivery (CNC) · checked once a month": "कोर पोर्टफ़ोलियो · ETF · डिलीवरी (CNC) · महीने में एक बार जाँच",
    "Monthly picks ✓ tested": "मासिक चयन ✓ परखा हुआ", "Intraday Top 10": "इंट्राडे टॉप 10", "Market pulse": "बाज़ार की नब्ज़", "Analyze a stock": "शेयर विश्लेषण",
    "Scanner": "स्कैनर", "India macro inputs": "भारत के आर्थिक आंकड़े", "How it works": "यह कैसे काम करता है",
    "NSE open": "NSE खुला", "NSE closed": "NSE बंद", "NSE Pre-market": "NSE प्री-मार्केट",
    /* common buttons & words */
    "↻ Refresh": "↻ रिफ़्रेश", "Refresh": "रिफ़्रेश", "Re-pick": "दोबारा चुनें", "Analyze": "विश्लेषण करें", "Run scan": "स्कैन चलाएँ", "Save inputs": "आंकड़े सेव करें",
    "📱 Telegram on": "📱 टेलीग्राम चालू", "📱 Telegram…": "📱 टेलीग्राम…", "🔔 Enable alerts": "🔔 अलर्ट चालू करें", "🔕 Enable alerts": "🔕 अलर्ट चालू करें", "🔔 Alerts on": "🔔 अलर्ट चालू",
    "✨ AI research note": "✨ AI रिसर्च नोट", "✨ AI market outlook": "✨ AI बाज़ार दृष्टिकोण", "Try:": "आज़माएँ:", "Recent:": "हाल के:", "edit": "बदलें",
    "Stock": "शेयर", "Do this": "क्या करें", "Shares": "शेयर संख्या", "Enter at": "एंट्री", "Stop-loss": "स्टॉप-लॉस", "Target": "टारगेट", "Profit / loss": "लाभ / हानि",
    "Price": "भाव", "Price now": "अभी का भाव", "Amount": "राशि", "Price ref.": "संदर्भ भाव", "Qty": "मात्रा", "Entry": "एंट्री", "Stop": "स्टॉप", "Upside": "बढ़त की संभावना",
    "Max loss": "अधिकतम नुकसान", "Target 1": "टारगेट 1", "Target 2": "टारगेट 2", "Target 1 (1.5R)": "टारगेट 1 (1.5R)", "Target 2 (2.5R)": "टारगेट 2 (2.5R)", "Target 2 (3R)": "टारगेट 2 (3R)",
    "score": "स्कोर", "today": "आज", "Up to": "अधिकतम", "Last": "अंतिम", "1 day": "1 दिन", "5 days": "5 दिन", "1 month": "1 महीना", "30 days": "30 दिन", "Index": "इंडेक्स",
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
    "BUY": "खरीदें", "SELL": "बेचें", "HOLD": "होल्ड", "BUY (wait)": "खरीदें (रुकें)", "SELL (wait)": "बेचें (रुकें)", "WAIT": "रुकें", "AVOID": "दूर रहें",
    "SELL / EXIT": "बेचें / निकलें", "SELL / AVOID": "बेचें / दूर रहें", "ACCUMULATE": "धीरे-धीरे खरीदें", "STRONG BUY": "ज़ोरदार खरीद",
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
    "NEUTRAL / MIXED": "तटस्थ / मिला-जुला", "Neutral / mixed": "तटस्थ / मिला-जुला", "RISK-ON": "जोखिम-पसंद", "RISK-OFF": "जोखिम-से बचाव", "STRONG RISK-OFF": "भारी जोखिम-से बचाव", "STRONG RISK-ON": "भारी जोखिम-पसंद",
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
    "Intraday": "इंट्राडे", "Swing · 2–6 weeks": "स्विंग · 2–6 हफ़्ते", "Long-term · 6–18 months": "लंबी अवधि · 6–18 महीने", "Swing": "स्विंग", "Long-term": "लंबी अवधि",
    "⚠ Before you trade": "⚠ ट्रेड से पहले", "▲ What's working for it": "▲ इसके पक्ष में क्या है", "▼ What's against it": "▼ इसके ख़िलाफ़ क्या है",
    "Daily chart": "दैनिक चार्ट", "last 180 sessions · hover for values": "पिछले 180 सत्र · मान देखने के लिए होवर करें", "Intraday · 5-minute": "इंट्राडे · 5-मिनट",
    "plan levels drawn on the chart": "योजना के स्तर चार्ट पर", "Technical factors": "तकनीकी पहलू", "Fundamentals": "फंडामेंटल्स", "Backtests": "बैकटेस्ट", "Stock news": "शेयर की ख़बरें",
    "Market regime:": "बाज़ार का माहौल:", "Market regime": "बाज़ार का माहौल", "Daily trend": "दैनिक ट्रेंड", "Strength vs Nifty": "निफ्टी की तुलना में मज़बूती", "Chart setup": "चार्ट सेटअप",
    "Liquidity": "तरलता", "Daily range (ATR)": "दैनिक दायरा (ATR)", "Central Pivot Range width": "सेंट्रल पिवट रेंज चौड़ाई", "Sector tailwind": "सेक्टर का रुख", "Daily range": "दैनिक दायरा",
    "Turnover": "टर्नओवर", "vs Nifty 5d / 20d": "निफ्टी की तुलना 5दिन / 20दिन", "Yesterday high / low": "कल का ऊपरी / निचला", "Moving-average trend": "मूविंग-एवरेज ट्रेंड",
    "ADX trend strength": "ADX ट्रेंड की ताकत", "Price momentum (3m/6m)": "भाव मोमेंटम (3म/6म)", "Fundamentals (value, quality, growth, health)": "फंडामेंटल्स (मूल्य, गुणवत्ता, विकास, सेहत)",
    "Long-term trend (DMAs, Supertrend, structure)": "लंबी अवधि का ट्रेंड (DMA, सुपरट्रेंड, संरचना)", "Momentum & relative strength": "मोमेंटम और सापेक्ष मज़बूती",
    "Macro regime (global + India)": "आर्थिक माहौल (वैश्विक + भारत)", "Daily trend:": "दैनिक ट्रेंड:", "Sector tailwind:": "सेक्टर का रुख:", "Chart setup:": "चार्ट सेटअप:", "News:": "ख़बरें:",
    "Universe": "शेयरों का समूह", "Your watchlist": "आपकी वॉचलिस्ट", "How Market Desk decides": "Market Desk कैसे फ़ैसला करता है",
    "Sources & limits": "स्रोत और सीमाएँ", "Before you trade": "ट्रेड से पहले",
    "Market Desk is a rule-based research tool, not investment advice. Signals are probabilities, not promises; past backtests do not guarantee future results. Always use a stop-loss and position sizes you can afford to lose. Data: Yahoo Finance, NSE, public RSS feeds.":
      "Market Desk नियमों पर आधारित एक रिसर्च टूल है, निवेश सलाह नहीं। सिग्नल संभावनाएँ हैं, वादे नहीं; पिछले बैकटेस्ट भविष्य की गारंटी नहीं देते। हमेशा स्टॉप-लॉस लगाएँ और उतनी ही रकम लगाएँ जिसका नुकसान आप सह सकें। डेटा: Yahoo Finance, NSE, सार्वजनिक RSS फ़ीड।",
    "Tailwinds dominate: favour long setups, buy dips in leaders, let winners run.": "अनुकूल माहौल हावी: खरीद वाले सेटअप चुनें, मज़बूत शेयरों में गिरावट पर खरीदें, जीतते ट्रेड को चलने दें।",
    "Mixed cues: be selective, trade smaller, prefer stocks with their own strength.": "मिले-जुले संकेत: चुनकर ट्रेड करें, छोटी रकम लगाएँ, अपनी ताकत वाले शेयर चुनें।",
    "Headwinds dominate: protect capital, tighten stops, prefer defensives (FMCG, pharma, IT on a weak rupee) or cash; intraday shorts on weak stocks.": "प्रतिकूल माहौल हावी: पूंजी बचाएँ, स्टॉप कड़े करें, सुरक्षित सेक्टर (FMCG, फार्मा, कमज़ोर रुपये पर IT) या नकद रखें; कमज़ोर शेयरों में इंट्राडे शॉर्ट।",
    "Strong risk-on": "भारी जोखिम-पसंद", "Risk-on": "जोखिम-पसंद", "Risk-off": "जोखिम-से बचाव", "Strong risk-off": "भारी जोखिम-से बचाव",
    "Not enough swings": "पर्याप्त उतार-चढ़ाव नहीं", "Broke below recent swing lows (downtrend)": "हाल के निचले स्तर से नीचे टूटा (डाउनट्रेंड)", "Broke above recent swing highs (uptrend)": "हाल के ऊपरी स्तर से ऊपर टूटा (अपट्रेंड)",
    "Higher highs & higher lows (uptrend)": "ऊँचे शिखर और ऊँचे निचले स्तर (अपट्रेंड)", "Lower highs & lower lows (downtrend)": "नीचे शिखर और नीचे निचले स्तर (डाउनट्रेंड)",
    "Expanding range (volatile)": "फैलता दायरा (उतार-चढ़ाव)", "Contracting range (coiling)": "सिकुड़ता दायरा (दबाव बन रहा)", "Nifty options (PCR)": "निफ्टी ऑप्शंस (PCR)",
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
    "Sell / short at": "बेचें / शॉर्ट करें", "No trade plan — the edge isn't strong enough.": "कोई ट्रेड योजना नहीं — बढ़त काफ़ी मज़बूत नहीं।",
    "No intraday edge right now — wait for price to clear VWAP / the opening range with volume.": "अभी इंट्राडे में कोई बढ़त नहीं — भाव के वॉल्यूम के साथ VWAP / शुरुआती दायरा पार करने का इंतज़ार करें।",
    "Trend and momentum are negative — avoid fresh longs; holders can exit or trail a tight stop.": "ट्रेंड और मोमेंटम नकारात्मक हैं — नई खरीद से बचें; जिनके पास है वे निकल सकते हैं या कड़ा ट्रेलिंग स्टॉप रखें।",
    "No swing entry — wait for the daily score to turn clearly positive.": "स्विंग एंट्री नहीं — दैनिक स्कोर साफ़ सकारात्मक होने का इंतज़ार करें।",
    "No intraday data.": "इंट्राडे डेटा नहीं।", "Nothing strongly positive.": "कुछ भी ज़ोरदार सकारात्मक नहीं।", "Nothing strongly negative.": "कुछ भी ज़ोरदार नकारात्मक नहीं।",
    "Session avg": "सत्र औसत", "Bollinger %B": "बोलिंजर %B", "Stoch %K / %D": "स्टोकेस्टिक %K / %D", "Williams %R": "विलियम्स %R", "Indicators & levels": "इंडिकेटर और स्तर", "MACD / signal": "MACD / सिग्नल",
    "Volume vs 20d": "वॉल्यूम बनाम 20 दिन", "52-wk high / low": "52-हफ़्ते ऊँचा / निचला", "Beta vs Nifty": "निफ्टी के मुकाबले बीटा", "Volatility (ann.)": "उतार-चढ़ाव (सालाना)",
    "Max drawdown 1y": "अधिकतम गिरावट 1 साल", "Return 1w": "रिटर्न 1 हफ़्ता", "Return 1m": "रिटर्न 1 महीना", "Return 3m": "रिटर्न 3 महीने", "Return 6m": "रिटर्न 6 महीने", "Return 1y": "रिटर्न 1 साल",
    "Support / resistance zones": "सपोर्ट / रेज़िस्टेंस ज़ोन", "Support": "सपोर्ट", "Resistance": "रेज़िस्टेंस", "dist": "दूरी", "touches": "छुआ", "Structure:": "बनावट:", "Candles:": "कैंडल:",
    "Key numbers": "मुख्य आंकड़े", "Market cap": "मार्केट कैप", "P/E · fwd P/E": "P/E · आगामी P/E", "Op. / net margin": "ऑपरेटिंग / शुद्ध मार्जिन",
    "Revenue / EPS growth": "राजस्व / EPS वृद्धि", "Debt/Equity": "कर्ज़/इक्विटी", "Promoter / inst.": "प्रमोटर / संस्थान", "Analyst target": "विश्लेषक टारगेट", "Consensus": "आम राय",
    "Next results": "अगले नतीजे", "EPS ttm / fwd": "EPS पिछले 12 महीने / आगामी", "About the business": "कारोबार के बारे में",
    "Yahoo Finance didn't return fundamentals for this symbol right now, so the long-term rating leans on trend, momentum, sector and macro only. Try ↻ later.": "Yahoo Finance ने अभी इस शेयर के फंडामेंटल्स नहीं दिए, इसलिए लंबी अवधि की रेटिंग सिर्फ़ ट्रेंड, मोमेंटम, सेक्टर और आर्थिक माहौल पर आधारित है। बाद में ↻ आज़माएँ।",
    "Driver": "कारक", "Sensitivity": "संवेदनशीलता", "Move now": "अभी की चाल", "Effect": "असर", "Reading": "मतलब",
    "Backtest — same rules, this stock's history": "बैकटेस्ट — यही नियम, इस शेयर के इतिहास पर", "Win rate": "जीत दर", "Avg trade": "औसत ट्रेड", "Avg win / loss": "औसत जीत / हार",
    "Profit factor": "प्रॉफ़िट फ़ैक्टर", "Total (compounded)": "कुल (चक्रवृद्धि)", "Max drawdown": "अधिकतम गिरावट", "Buy & hold": "खरीदकर रखना", "Long / short wins": "खरीद / शॉर्ट जीत",
    "Recent trades": "हाल के ट्रेड", "No trades.": "कोई ट्रेड नहीं।", "LONG": "खरीद", "SHORT": "शॉर्ट", "Bollinger (20,2)": "बोलिंजर (20,2)",
    "✔ These rules have worked on this stock — signals deserve more weight.": "✔ ये नियम इस शेयर पर काम करते रहे हैं — सिग्नल को ज़्यादा महत्व दें।",
    "✖ These rules have lost money on this stock — treat its signals with caution.": "✖ इन नियमों से इस शेयर पर नुकसान हुआ है — सिग्नल को सावधानी से लें।",
    "● Mixed record — use the signals with other confirmation.": "● मिला-जुला रिकॉर्ड — सिग्नल को दूसरी पुष्टि के साथ इस्तेमाल करें।",
    "Costs included (0.25% swing, 0.08% intraday). Past results do not guarantee future ones.": "खर्च शामिल (स्विंग 0.25%, इंट्राडे 0.08%)। पिछले नतीजे भविष्य की गारंटी नहीं।",
    "ACCUMULATE ON DIPS": "गिरावट पर धीरे-धीरे खरीदें", "REDUCE": "कम करें", "WATCH — LONG BIAS": "नज़र रखें — खरीद का झुकाव", "WATCH — SHORT BIAS": "नज़र रखें — शॉर्ट का झुकाव",
    "WATCH — WEAK": "नज़र रखें — कमज़ोर", "NO CLEAR EDGE": "साफ़ बढ़त नहीं", "NO TRADE": "कोई ट्रेड नहीं",
    /* analyze a stock — factor names from the server */
    "Supertrend (10,3)": "सुपरट्रेंड (10,3)", "Supertrend": "सुपरट्रेंड", "Relative strength vs Nifty": "निफ्टी के मुकाबले सापेक्ष मज़बूती", "Volume: accumulation / distribution": "वॉल्यूम: संचय / वितरण",
    "52-week range position": "52-हफ़्ते के दायरे में स्थिति", "Money Flow Index": "मनी फ़्लो इंडेक्स", "Price vs VWAP": "भाव बनाम VWAP", "Price vs session average": "भाव बनाम सत्र औसत",
    "EMA 9/21 crossover": "EMA 9/21 क्रॉसओवर", "MACD histogram": "MACD हिस्टोग्राम", "Opening-range breakout (15 min)": "शुरुआती दायरे का ब्रेकआउट (15 मिनट)",
    "Volume pressure (last 30 min)": "वॉल्यूम दबाव (पिछले 30 मिनट)", "Day structure (open / prev close)": "दिन की बनावट (ओपन / पिछला बंद)", "Central Pivot Range": "सेंट्रल पिवट रेंज",
    "Intraday strength vs Nifty": "निफ्टी के मुकाबले इंट्राडे मज़बूती",
    "P/E (trailing)": "P/E (पिछला)", "P/E vs sector norm": "P/E बनाम सेक्टर मानक", "Forward vs trailing P/E": "आगामी बनाम पिछला P/E", "Price / Book": "भाव / बुक", "PEG ratio": "PEG अनुपात",
    "Return on equity": "इक्विटी पर रिटर्न (ROE)", "Operating margin": "ऑपरेटिंग मार्जिन", "Net profit margin": "शुद्ध लाभ मार्जिन", "Return on assets": "संपत्ति पर रिटर्न (ROA)",
    "Revenue growth (YoY)": "राजस्व वृद्धि (सालाना)", "Earnings growth (YoY)": "मुनाफ़ा वृद्धि (सालाना)", "Debt / Equity": "कर्ज़ / इक्विटी", "Current ratio": "करंट रेशियो", "Free cash flow": "फ़्री कैश फ़्लो",
    "Promoter / insider holding": "प्रमोटर / अंदरूनी हिस्सेदारी", "Institutional holding (FII+DII)": "संस्थागत हिस्सेदारी (FII+DII)", "Analyst target upside": "विश्लेषक टारगेट तक बढ़त",
    "Analyst consensus": "विश्लेषकों की आम राय", "Dividend yield": "डिविडेंड यील्ड",
    "Value": "मूल्य", "Quality": "गुणवत्ता", "Growth": "विकास", "Health": "सेहत", "Ownership": "हिस्सेदारी", "Street": "विश्लेषक",
    "loss-making": "घाटे में", "positive": "सकारात्मक", "negative": "नकारात्मक",
    "buy": "खरीदें", "hold": "होल्ड", "sell": "बेचें", "strong_buy": "ज़ोरदार खरीद", "strong_sell": "ज़ोरदार बिक्री", "underperform": "कमज़ोर प्रदर्शन", "outperform": "बेहतर प्रदर्शन",
    /* analyze a stock — fixed notes from the server */
    "Not enough intraday bars yet.": "अभी पर्याप्त इंट्राडे कैंडल नहीं।", "Still inside the opening range — no breakout yet.": "अभी शुरुआती दायरे के अंदर — कोई ब्रेकआउट नहीं।",
    "Opening 15 minutes: spreads are wide and moves reverse often — wait for the opening range to form (9:30).": "पहले 15 मिनट: स्प्रेड चौड़े होते हैं और चाल अक्सर पलटती है — शुरुआती दायरा बनने (9:30) तक रुकें।",
    "After 2:30 PM: too little time left for a fresh intraday trade to work; manage open positions only.": "दोपहर 2:30 के बाद: नए इंट्राडे ट्रेड के लिए बहुत कम समय बचा है; सिर्फ़ खुले ट्रेड संभालें।",
    "Swing (daily score ≥ +0.30, 2-ATR stop, 4-ATR target)": "स्विंग (दैनिक स्कोर ≥ +0.30, 2-ATR स्टॉप, 4-ATR टारगेट)",
    "Intraday (15-min score crosses ±0.45, 1.2-ATR stop, 2-ATR target, exit 3:15 PM)": "इंट्राडे (15-मिनट स्कोर ±0.45 पार करे, 1.2-ATR स्टॉप, 2-ATR टारगेट, 3:15 बजे निकास)",
    "No signals fired in the test window.": "जाँच की अवधि में कोई सिग्नल नहीं आया।",
    "Negative earnings — no P/E support.": "मुनाफ़ा नकारात्मक — P/E का सहारा नहीं।",
    "Forward P/E below trailing — analysts expect earnings to grow.": "आगामी P/E पिछले से कम — विश्लेषकों को मुनाफ़ा बढ़ने की उम्मीद है।",
    "Forward P/E above trailing — earnings expected to shrink.": "आगामी P/E पिछले से ज़्यादा — मुनाफ़ा घटने की उम्मीद।",
    "Growth available cheaply (PEG < 1).": "विकास सस्ते में मिल रहा है (PEG < 1)।", "Paying a lot for the growth (PEG > 2.5).": "विकास के लिए बहुत ज़्यादा कीमत (PEG > 2.5)।",
    "Reasonable price for the growth.": "विकास के हिसाब से ठीक कीमत।",
    "High ROE — efficient use of shareholder capital (a hallmark of Indian compounders).": "ऊँचा ROE — शेयरधारकों की पूंजी का कुशल इस्तेमाल (लंबे समय तक बढ़ने वाली भारतीय कंपनियों की पहचान)।",
    "Low ROE — capital earns below its cost.": "कम ROE — पूंजी अपनी लागत से कम कमाती है।", "Adequate ROE.": "ठीक-ठाक ROE।", "Loss-making.": "घाटे में।",
    "For banks RoA ≥ 1.2% is strong.": "बैंकों के लिए RoA ≥ 1.2% मज़बूत है।", "Asset efficiency.": "संपत्ति का कुशल इस्तेमाल।",
    "Strong top-line growth, ahead of nominal GDP.": "बिक्री में मज़बूत वृद्धि, नॉमिनल GDP से आगे।", "Revenue shrinking.": "राजस्व घट रहा है।",
    "Revenue growing slower than the economy.": "राजस्व अर्थव्यवस्था से धीमा बढ़ रहा है।", "Growing roughly with the economy.": "लगभग अर्थव्यवस्था की रफ़्तार से बढ़ रहा है।",
    "Profits compounding fast — earnings drive long-term returns.": "मुनाफ़ा तेज़ी से बढ़ रहा है — लंबी अवधि का रिटर्न मुनाफ़े से ही आता है।", "Profits falling.": "मुनाफ़ा गिर रहा है।",
    "Profits barely growing.": "मुनाफ़ा मुश्किल से बढ़ रहा है।", "Moderate profit growth.": "मध्यम मुनाफ़ा वृद्धि।",
    "Nearly debt-free — resilient to rate hikes.": "लगभग कर्ज़-मुक्त — ब्याज दर बढ़ने पर भी मज़बूत।", "Highly leveraged — vulnerable to rising rates / slowdowns.": "भारी कर्ज़ — बढ़ती दरों / मंदी में कमज़ोर।",
    "Meaningful debt — watch interest costs.": "अच्छा-ख़ासा कर्ज़ — ब्याज खर्च पर नज़र रखें।", "Manageable leverage.": "संभालने लायक कर्ज़।",
    "Short-term liabilities exceed short-term assets.": "छोटी अवधि की देनदारियाँ छोटी अवधि की संपत्ति से ज़्यादा हैं।", "Comfortable liquidity.": "आरामदायक नकदी स्थिति।",
    "Generates cash after capex — can fund growth/dividends itself.": "पूंजी खर्च के बाद भी नकदी कमाती है — विकास/डिविडेंड ख़ुद चला सकती है।",
    "Burning cash — depends on borrowing or equity.": "नकदी ख़र्च हो रही है — कर्ज़ या नई इक्विटी पर निर्भर।",
    "High promoter skin in the game.": "प्रमोटर की बड़ी हिस्सेदारी दांव पर।", "Low promoter holding (common for professionally-run cos).": "कम प्रमोटर हिस्सेदारी (पेशेवर प्रबंधन वाली कंपनियों में आम)।",
    "Moderate promoter holding.": "मध्यम प्रमोटर हिस्सेदारी।", "Institutional sponsorship supports liquidity and re-rating.": "संस्थागत निवेशकों का साथ तरलता और री-रेटिंग में मदद करता है।",
    "Scale 1 = strong buy … 5 = sell.": "पैमाना 1 = ज़ोरदार खरीद … 5 = बेचें।", "Cash returned to shareholders.": "शेयरधारकों को लौटाई गई नकदी।",
    "Existing holders can stay; fresh money should wait for trend confirmation (close above the 50-DMA with volume).": "जिनके पास है वे बने रह सकते हैं; नया पैसा ट्रेंड की पुष्टि (वॉल्यूम के साथ 50-DMA के ऊपर बंद) का इंतज़ार करे।",
    "Trim on rallies; re-assess only after price reclaims the 200-DMA.": "तेज़ी पर कुछ बेचें; भाव के 200-DMA पर लौटने के बाद ही दोबारा आंकें।",
    /* candlestick patterns */
    "Doji": "डोजी", "Indecision; watch the next candle.": "अनिश्चितता; अगली कैंडल देखें।", "Hammer": "हैमर", "Buyers rejected lower prices after a fall.": "गिरावट के बाद खरीदारों ने निचले भाव ठुकराए।",
    "Hanging man": "हैंगिंग मैन", "Selling pressure appearing after a rise.": "तेज़ी के बाद बिकवाली का दबाव दिख रहा है।", "Shooting star": "शूटिंग स्टार",
    "Sellers rejected higher prices after a rise.": "तेज़ी के बाद बिकवालों ने ऊँचे भाव ठुकराए।", "Inverted hammer": "उलटा हैमर", "Early buying interest after a fall.": "गिरावट के बाद शुरुआती खरीद रुचि।",
    "Bullish engulfing": "बुलिश एनगल्फ़िंग", "Buyers overwhelmed the prior down candle.": "खरीदारों ने पिछली गिरावट वाली कैंडल को पूरी तरह ढक लिया।", "Bearish engulfing": "बेयरिश एनगल्फ़िंग",
    "Sellers overwhelmed the prior up candle.": "बिकवालों ने पिछली बढ़त वाली कैंडल को पूरी तरह ढक लिया।", "Inside bar": "इनसाइड बार",
    "Consolidation; a break of the mother bar sets direction.": "ठहराव; मदर बार टूटने से दिशा तय होगी।", "Bullish marubozu": "बुलिश मारुबोज़ू", "Bearish marubozu": "बेयरिश मारुबोज़ू",
    "One side in control all session.": "पूरे सत्र एक ही पक्ष हावी रहा।", "Morning star": "मॉर्निंग स्टार", "Three-candle bullish reversal.": "तीन कैंडल वाला तेज़ी का पलटाव।",
    "Evening star": "ईवनिंग स्टार", "Three-candle bearish reversal.": "तीन कैंडल वाला मंदी का पलटाव।",
    /* sectors, their stories and the macro drivers */
    "IT services": "आईटी सेवाएँ", "Pharma": "फार्मा", "Private banks": "निजी बैंक", "PSU banks": "सरकारी बैंक", "NBFC / financials": "NBFC / वित्तीय", "Insurance": "बीमा", "Automobiles": "ऑटोमोबाइल",
    "Metals & mining": "धातु और खनन", "Oil & gas producers": "तेल और गैस उत्पादक", "Refiners / OMCs": "रिफ़ाइनर / तेल विपणन कंपनियाँ", "Energy (diversified)": "ऊर्जा (विविध)",
    "Gas utilities": "गैस वितरण", "Power & utilities": "बिजली और यूटिलिटी", "Real estate": "रियल एस्टेट", "Capital goods": "कैपिटल गुड्स", "Infrastructure": "इंफ्रास्ट्रक्चर", "Defence": "रक्षा",
    "Cement": "सीमेंट", "Chemicals": "केमिकल", "Paints": "पेंट", "Aviation": "एविएशन", "Telecom": "टेलीकॉम", "Consumer discretionary": "उपभोक्ता (गैर-ज़रूरी)", "Jewellery": "ज्वेलरी",
    "Hospitals": "अस्पताल", "Media": "मीडिया", "Diversified": "विविध",
    "Earns in dollars: gains from a weaker rupee and strong US tech spending.": "डॉलर में कमाई: कमज़ोर रुपये और अमेरिका में मज़बूत टेक खर्च से फ़ायदा।",
    "Export-heavy and defensive: weaker rupee helps, holds up in sell-offs.": "निर्यात पर निर्भर और सुरक्षित: कमज़ोर रुपया मदद करता है, गिरावट में टिका रहता है।",
    "Biggest FII holding: sensitive to flows, rates and credit growth.": "FII की सबसे बड़ी हिस्सेदारी: निवेश प्रवाह, ब्याज दर और कर्ज़ वृद्धि के प्रति संवेदनशील।",
    "High-beta domestic cyclicals; bond-yield and asset-quality sensitive.": "ज़्यादा उतार-चढ़ाव वाले घरेलू चक्रीय शेयर; बॉन्ड यील्ड और एसेट क्वालिटी के प्रति संवेदनशील।",
    "Borrow to lend: falling rates widen margins.": "उधार लेकर कर्ज़ देते हैं: गिरती ब्याज दरें मार्जिन बढ़ाती हैं।",
    "Long-duration businesses; like stable, falling yields.": "लंबी अवधि का कारोबार; स्थिर, गिरती यील्ड इनके लिए अच्छी।",
    "Fuel prices hit demand; metal prices hit margins; rural income matters.": "ईंधन के दाम मांग पर, धातु के दाम मार्जिन पर असर डालते हैं; ग्रामीण आय अहम है।",
    "Defensive; crude-linked packaging costs; depends on rural demand & monsoon.": "सुरक्षित सेक्टर; पैकेजिंग लागत कच्चे तेल से जुड़ी; ग्रामीण मांग और मानसून पर निर्भर।",
    "Priced globally: China demand and the dollar decide.": "दाम वैश्विक स्तर पर तय: चीन की मांग और डॉलर फ़ैसला करते हैं।",
    "ONGC/Oil India realise more when crude rises.": "कच्चा तेल चढ़ने पर ONGC/Oil India को ज़्यादा दाम मिलते हैं।",
    "BPCL/HPCL/IOC: costlier crude squeezes marketing margins.": "BPCL/HPCL/IOC: महँगा कच्चा तेल मार्केटिंग मार्जिन दबाता है।",
    "Mixed exposure to oil, gas, retail, telecom.": "तेल, गैस, रिटेल, टेलीकॉम — मिला-जुला कारोबार।", "City-gas margins shrink when LNG costs rise.": "LNG महँगी होने पर सिटी-गैस मार्जिन घटते हैं।",
    "Capex-heavy, rate-sensitive, riding India’s power demand.": "भारी पूंजी निवेश, ब्याज दर के प्रति संवेदनशील, भारत की बढ़ती बिजली मांग से फ़ायदा।",
    "Most rate-sensitive sector: home-loan rates drive demand.": "ब्याज दर के प्रति सबसे संवेदनशील सेक्टर: होम-लोन दरें मांग तय करती हैं।",
    "Government & private capex cycle.": "सरकारी और निजी पूंजी निवेश का चक्र।", "Order books tied to government spending; bitumen/fuel costs.": "ऑर्डर बुक सरकारी खर्च से जुड़ी; बिटुमेन/ईंधन की लागत।",
    "Government orders & indigenisation; geopolitics can lift sentiment.": "सरकारी ऑर्डर और स्वदेशीकरण; भू-राजनीति माहौल सुधार सकती है।",
    "Energy is ~30% of cost (pet coke, diesel).": "लागत का ~30% ऊर्जा है (पेट कोक, डीज़ल)।", "Crude-derived inputs; export-oriented; China competition.": "कच्चे तेल से बने कच्चे माल; निर्यात पर ज़ोर; चीन से होड़।",
    "~50% of raw materials are crude derivatives.": "~50% कच्चा माल कच्चे तेल से बनता है।", "Jet fuel is the biggest cost; leases are in dollars.": "जेट ईंधन सबसे बड़ी लागत; लीज़ डॉलर में।",
    "Defensive cash flows; tariff hikes drive earnings.": "स्थिर नकदी प्रवाह; टैरिफ़ बढ़ोतरी से कमाई बढ़ती है।", "Urban spending, festive demand, inflation.": "शहरी खर्च, त्योहारी मांग, महंगाई।",
    "Gold price vs wedding demand.": "सोने का भाव बनाम शादियों की मांग।", "Defensive, structural growth.": "सुरक्षित, लंबी अवधि की वृद्धि।", "Ad-spend cycle.": "विज्ञापन खर्च का चक्र।",
    "Moves with the broad market.": "पूरे बाज़ार के साथ चलता है।",
    "Global stocks": "वैश्विक शेयर", "US VIX": "अमेरिकी VIX", "US yields": "अमेरिकी यील्ड", "Dollar": "डॉलर", "Crude": "कच्चा तेल", "Nat gas": "प्राकृतिक गैस", "US short rates": "अमेरिकी अल्पकालिक दरें",
    /* news topics */
    "RBI & rates": "RBI और ब्याज दर", "US Fed": "अमेरिकी फ़ेड", "Inflation": "महंगाई", "Rupee & FX": "रुपया और विदेशी मुद्रा", "FII / DII flows": "FII / DII प्रवाह", "Earnings": "नतीजे",
    "Geopolitics": "भू-राजनीति", "Trade & tariffs": "व्यापार और टैरिफ़", "Govt policy & budget": "सरकारी नीति और बजट", "Monsoon & rural": "मानसून और ग्रामीण", "Regulation (SEBI)": "नियमन (SEBI)",
    "IPOs": "IPO", "Elections": "चुनाव",
  };
  /* sector-norm words used inside the P/E note */
  const NORM_HI = { "IT services": "आईटी सेवाएँ", "pharma": "फार्मा", "private bank": "निजी बैंक", "PSU bank": "सरकारी बैंक", "NBFC/financials": "NBFC/वित्तीय", "insurance": "बीमा", "auto": "ऑटो",
    "FMCG": "FMCG", "metals": "धातु", "upstream oil": "तेल उत्पादन", "refining/marketing": "रिफ़ाइनिंग/मार्केटिंग", "energy": "ऊर्जा", "power utility": "बिजली कंपनी", "real estate": "रियल एस्टेट",
    "capital goods": "कैपिटल गुड्स", "cement": "सीमेंट", "chemicals": "केमिकल", "paints": "पेंट", "aviation": "एविएशन", "telecom": "टेलीकॉम", "consumer discretionary": "उपभोक्ता (गैर-ज़रूरी)",
    "jewellery": "ज्वेलरी", "hospitals": "अस्पताल", "media": "मीडिया", "defence": "रक्षा", "infrastructure": "इंफ्रास्ट्रक्चर", "market": "बाज़ार" };
  const PRICE_BITS = { "above 50-DMA": "50-DMA से ऊपर", "below 50-DMA": "50-DMA से नीचे", "above 200-DMA": "200-DMA से ऊपर", "below 200-DMA": "200-DMA से नीचे",
    "50>200 (golden-cross regime)": "50>200 (गोल्डन-क्रॉस दौर)", "50<200 (death-cross regime)": "50<200 (डेथ-क्रॉस दौर)", "50-DMA rising": "50-DMA चढ़ रहा", "50-DMA falling": "50-DMA गिर रहा" };
  const UPDN = { above: "ऊपर", below: "नीचे", Above: "ऊपर", Below: "नीचे", rising: "बढ़ रहा", falling: "घट रहा", positive: "सकारात्मक", negative: "नकारात्मक" };
  const hx = s => Object.prototype.hasOwnProperty.call(HI, s) ? HI[s] : s;
  const whyHi = w => { const m = w.match(/^(.+) (up|down) (helps|hurts)$/); return m ? hx(m[1]) + (m[2] === "up" ? " ऊपर" : " नीचे") + (m[3] === "helps" ? " — मदद" : " — नुकसान") : w; };

  /* phrases with numbers: full-text patterns */
  const HI_RX = [
    [/^weight (\d+)%$/, "भार $1%"], [/^· weight (\d+)%$/, "· भार $1%"], [/^(.+) · weight (\d+)%$/, (m, a, w) => trx(a) + " · भार " + w + "%"],
    /* analyze a stock — factor values */
    [/^([+-]?[\d.]+)% vs (50|200)-DMA$/, "$2-DMA के मुकाबले $1%"], [/^([\d.]+)x up\/down vol$/, "$1x बढ़त/गिरावट वॉल्यूम"], [/^(\d+)% of range$/, "दायरे का $1%"],
    [/^([+-]?[\d.]+)% \(3m\)$/, "$1% (3 महीने)"], [/^([+-][\d.]+) pts$/, "$1 अंक"], [/^([\d.]+)x \(norm ~([\d.]+)x\)$/, "$1x (मानक ~$2x)"], [/^([\d.]+)x fwd$/, "$1x आगामी"],
    [/^([\d.]+) \(([a-z_]+|—)\)$/, (m, a, k) => a + " (" + hx(k) + ")"], [/^(\d+) bars$/, "$1 कैंडल"],
    /* analyze a stock — daily technical notes */
    [/^Price (.+)\.$/, (m, b) => { const bits = b.split(", "); return bits.every(x => PRICE_BITS[x]) ? "भाव " + bits.map(x => PRICE_BITS[x]).join(", ") + "।" : m; }],
    [/^ADX (\d+): no real trend — range-bound, signals less reliable\.$/, "ADX $1: कोई असली ट्रेंड नहीं — दायरे में, सिग्नल कम भरोसेमंद।"],
    [/^ADX (\d+) with \+DI above -DI: buyers driving the trend\.$/, "ADX $1, +DI ऊपर -DI से: खरीदार ट्रेंड चला रहे हैं।"],
    [/^ADX (\d+) with -DI above \+DI: sellers driving the trend\.$/, "ADX $1, -DI ऊपर +DI से: बिकवाल ट्रेंड चला रहे हैं।"],
    [/^In buy mode; trailing support at ([\d.]+)\.$/, "खरीद मोड में; ट्रेलिंग सपोर्ट $1 पर।"], [/^In sell mode; overhead resistance at ([\d.]+)\.$/, "बिक्री मोड में; ऊपर रेज़िस्टेंस $1 पर।"],
    [/^RSI (\d+) — bullish momentum zone\.$/, "RSI $1 — तेज़ी वाला मोमेंटम ज़ोन।"], [/^RSI (\d+) — bearish momentum zone\.$/, "RSI $1 — मंदी वाला मोमेंटम ज़ोन।"],
    [/^RSI (\d+) — overbought: strong, but stretched; better to buy dips than chase\.$/, "RSI $1 — ओवरबॉट: मज़बूत, पर खिंचा हुआ; पीछा करने से बेहतर गिरावट पर खरीदें।"],
    [/^RSI (\d+) — oversold: weak, but a relief bounce is likely\.$/, "RSI $1 — ओवरसोल्ड: कमज़ोर, पर राहत वाला उछाल संभव।"],
    [/^RSI (\d+) — exhausted, avoid fresh longs\.$/, "RSI $1 — थका हुआ, नई खरीद से बचें।"], [/^RSI (\d+) — exhausted, avoid fresh shorts\.$/, "RSI $1 — थका हुआ, नए शॉर्ट से बचें।"],
    [/^RSI (\d+) — bullish\.$/, "RSI $1 — तेज़ी।"], [/^RSI (\d+) — bearish\.$/, "RSI $1 — मंदी।"],
    [/^Histogram (positive|negative) and (rising|falling); MACD line (above|below) zero\.$/, (m, a, b, c) => "हिस्टोग्राम " + UPDN[a] + " और " + UPDN[b] + "; MACD लाइन शून्य से " + UPDN[c] + "।"],
    [/^Histogram (positive|negative), (rising|falling)\.$/, (m, a, b) => "हिस्टोग्राम " + UPDN[a] + ", " + UPDN[b] + "।"],
    [/^3-month ([+-][\d.]+%)(?:, 6-month ([+-][\d.]+%))?\. Stocks with strong 3–12 month momentum tend to keep outperforming \(momentum effect\)\.$/,
      (m, a, b) => "3 महीने " + a + (b ? ", 6 महीने " + b : "") + "। 3–12 महीने के मज़बूत मोमेंटम वाले शेयर आगे भी अक्सर बेहतर करते रहते हैं (मोमेंटम असर)।"],
    [/^(Outperformed|Underperformed) Nifty 50 by ([\d.]+) pts over 3 months\.$/, (m, a, b) => "3 महीनों में निफ्टी 50 से " + b + " अंक " + (a === "Outperformed" ? "बेहतर" : "कमज़ोर") + " रहा।"],
    [/^Up-day volume is ([\d.]+)x down-day volume over 20 sessions; OBV (rising|falling) \((?:accumulation|distribution)\)\.(?: Today ([\d.]+)x average volume\.)?$/,
      (m, a, o, t) => "पिछले 20 सत्रों में बढ़त वाले दिनों का वॉल्यूम गिरावट वाले दिनों का " + a + " गुना; OBV " + (o === "rising" ? "चढ़ रहा (संचय)" : "गिर रहा (वितरण)") + "।" + (t ? " आज औसत का " + t + " गुना वॉल्यूम।" : "")],
    [/^([\d.]+)% below the 52-week high \(([\d.]+)\); (near highs|near lows|mid-range)/, (m, a, h, k) => "52-हफ़्ते के ऊँचे स्तर (" + h + ") से " + a + "% नीचे; " +
      ({ "near highs": "ऊँचाई के पास — ब्रेकआउट ज़ोन, ऊपर बिकवाली का दबाव नहीं।", "near lows": "निचले स्तर के पास — जब तक उलटा साबित न हो, डाउनट्रेंड में।", "mid-range": "दायरे के बीच में।" }[k])],
    [/^MFI (\d+) — volume-weighted buying (exceeds|trails) selling\.$/, (m, a, b) => "MFI " + a + " — वॉल्यूम के हिसाब से खरीदारी बिकवाली से " + (b === "exceeds" ? "ज़्यादा" : "कम") + "।"],
    [/^MFI (\d+) — overbought on money flow\.$/, "MFI $1 — मनी फ़्लो में ओवरबॉट।"], [/^MFI (\d+) — oversold on money flow\.$/, "MFI $1 — मनी फ़्लो में ओवरसोल्ड।"],
    /* analyze a stock — intraday notes */
    [/^(Above|Below) (VWAP|average price) ([\d.]+) — intraday buyers are (in profit|under water), (?:dips tend to get bought|rallies tend to get sold)\.$/,
      (m, a, b, v, c) => (b === "VWAP" ? "VWAP" : "औसत भाव") + " " + v + " से " + UPDN[a] + " — " + (c === "in profit" ? "इंट्राडे खरीदार मुनाफ़े में हैं, गिरावट पर खरीदारी होती है।" : "इंट्राडे खरीदार घाटे में हैं, तेज़ी पर बिकवाली होती है।")],
    [/^EMA9 (above|below) EMA21, price (above|below) EMA9\.$/, (m, a, b) => "EMA9, EMA21 से " + UPDN[a] + "; भाव EMA9 से " + UPDN[b] + "।"],
    [/^(Buy|Sell) mode, trailing stop ([\d.]+)\.$/, (m, a, b) => (a === "Buy" ? "खरीद" : "बिक्री") + " मोड, ट्रेलिंग स्टॉप " + b + "।"],
    [/^Broke (above|below) the opening range (?:high|low) ([\d.]+)\.$/, (m, a, b) => "शुरुआती दायरे के " + (a === "above" ? "ऊपरी" : "निचले") + " स्तर " + b + " से " + UPDN[a] + " टूटा।"],
    [/^(Buyers|Sellers) in control: volume is concentrated on (?:up|down)-closes \(([+-][\d.]+)\)(?:; last bar ([\d.]+)x normal volume)?\.$/,
      (m, a, p, v) => (a === "Buyers" ? "खरीदार हावी: वॉल्यूम ऊपर बंद होने वाली कैंडलों पर" : "बिकवाल हावी: वॉल्यूम नीचे बंद होने वाली कैंडलों पर") + " (" + p + ")" + (v ? "; आखिरी कैंडल पर सामान्य से " + v + " गुना वॉल्यूम" : "") + "।"],
    [/^Gap ([+-][\d.]+%), now ([+-][\d.]+%) on the day, (above|below) the open( — gap-up being sold\.| — gap-down being bought\.|\.)$/,
      (m, g, c, a, t) => "गैप " + g + ", दिन में अभी " + c + ", ओपन से " + UPDN[a] + (t === "." ? "।" : t.indexOf("gap-up") >= 0 ? " — गैप-अप पर बिकवाली।" : " — गैप-डाउन पर खरीदारी।")],
    [/^(Above|Below|Inside) CPR — (?:bullish day bias|bearish day bias|undecided)\. CPR width ([\d.]+)%( \(narrow → trending day likely\)\.|\.)$/,
      (m, a, w, t) => ({ Above: "CPR से ऊपर — दिन का रुख तेज़ी का।", Below: "CPR से नीचे — दिन का रुख मंदी का।", Inside: "CPR के अंदर — रुख तय नहीं।" }[a]) + " CPR चौड़ाई " + w + "%" + (t === "." ? "।" : " (संकरा → ट्रेंड वाला दिन संभव)।")],
    [/^(Outperforming|Underperforming) Nifty by ([\d.]+) pts today\.$/, (m, a, b) => "आज निफ्टी से " + b + " अंक " + (a === "Outperforming" ? "बेहतर" : "कमज़ोर") + "।"],
    [/^Daily trend score ([+-][\d.]+) supports (longs|shorts)\.$/, (m, a, b) => "दैनिक ट्रेंड स्कोर " + a + (b === "longs" ? " खरीद" : " शॉर्ट") + " के पक्ष में।"],
    [/^Market regime ([+-][\d.]+) \((risk-on|risk-off|neutral)\) — (?:tailwind for longs|tailwind for shorts|no help either way)\.$/,
      (m, a, b) => "बाज़ार का माहौल " + a + " (" + { "risk-on": "जोखिम-पसंद) — खरीद के लिए अनुकूल।", "risk-off": "जोखिम-से बचाव) — शॉर्ट के लिए अनुकूल।", neutral: "तटस्थ) — किसी तरफ़ मदद नहीं।" }[b]],
    [/^15-minute score ([+-][\d.]+) (agrees|disagrees) with 5-minute/, (m, a, b) => "15-मिनट स्कोर " + a + (b === "agrees" ? " 5-मिनट से सहमत।" : " 5-मिनट से असहमत — कम भरोसा।")],
    [/^India VIX ([\d.]+) is elevated — halve position size, expect wider swings\.$/, "India VIX $1 ऊँचा है — ट्रेड का आकार आधा करें, बड़े उतार-चढ़ाव की उम्मीद रखें।"],
    [/^Results due (.+) — event risk; gaps can jump stops\.$/, "नतीजे $1 को — इवेंट जोखिम; गैप स्टॉप को लांघ सकता है।"],
    [/^Relative volume ([\d.]+)x — thin participation, breakouts less reliable\.$/, "सापेक्ष वॉल्यूम $1x — कम भागीदारी, ब्रेकआउट कम भरोसेमंद।"],
    /* analyze a stock — fundamentals notes */
    [/^Trades at (\d+)% of the typical (.+) multiple — (cheap vs peers|premium valuation; growth must deliver|fairly valued)\.$/,
      (m, p, k, t) => "सामान्य " + (NORM_HI[k] || k) + " मल्टीपल के " + p + "% पर कारोबार — " + ({ "cheap vs peers": "साथियों से सस्ता।", "fairly valued": "उचित मूल्य।" }[t] || "प्रीमियम मूल्यांकन; विकास को साबित करना होगा।")],
    [/^For lenders P\/B is the key valuation — norm ~([\d.]+)x\.$/, "कर्ज़ देने वालों के लिए P/B मुख्य मूल्यांकन है — मानक ~$1x।"], [/^Norm for the sector ~([\d.]+)x\.$/, "सेक्टर का मानक ~$1x।"],
    [/^(Above|Below) the sector norm of ~(\d+)%\.$/, (m, a, b) => "सेक्टर मानक ~" + b + "% से " + UPDN[a] + "।"], [/^Keeps ([\d.]+)p of every ₹1 of sales\.$/, "हर ₹1 की बिक्री में से $1 पैसे मुनाफ़ा।"],
    [/^Mean target (₹[\d.]+)(?: from (\d+) analysts)?\.$/, (m, a, n) => "औसत टारगेट " + a + (n ? " (" + n + " विश्लेषकों से)" : "") + "।"],
    /* analyze a stock — long-term verdict and sector */
    [/^Sector tailwind \((.+)\)$/, (m, a) => "सेक्टर का रुख (" + hx(a) + ")"],
    [/^Sector (tailwind|headwind): (.+?)(?: — (.+))?\.$/, (m, a, l, w) => "सेक्टर " + (a === "tailwind" ? "अनुकूल" : "प्रतिकूल") + ": " + hx(l) + (w ? " — " + w.split("; ").map(whyHi).join("; ") : "") + "।"],
    [/^Stretched \(RSI > 70\): stagger buys — 1\/3 now, add near (₹[\d.,]+) \(21-EMA\) or on a breakout retest\.$/, "खिंचा हुआ (RSI > 70): किश्तों में खरीदें — 1/3 अभी, बाकी $1 (21-EMA) के पास या ब्रेकआउट दोबारा परखे जाने पर।"],
    [/^Buy in 2–3 tranches over the next few weeks; add on dips toward (₹[\d.,]+)\.$/, "अगले कुछ हफ़्तों में 2–3 किश्तों में खरीदें; $1 की ओर गिरावट पर और जोड़ें।"],
    [/^(Benefits|Hurt) when (.+) rises(?:; it is currently (rising|falling|flat))?\.$/, (m, a, d, s) => hx(d) + " चढ़ने पर " + (a === "Benefits" ? "फ़ायदा" : "नुकसान") +
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
    [/ · sentiment /g, " · भावना "], [/^sentiment /, "भावना "], [/ min ago/g, " मिनट पहले"], [/ h ago/g, " घंटे पहले"], [/ d ago/g, " दिन पहले"],
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
