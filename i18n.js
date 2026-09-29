/* =====================================================================
   Market Desk — English / हिंदी (i18n.js)
   ---------------------------------------------------------------------
   The page is written in English. With Hindi switched on, this file
   swaps every piece of visible text it knows — exact phrases (HI) and
   phrases with numbers in them (HI_RX, SUBS) — as the page renders,
   using a MutationObserver, and swaps it back for English. Longer
   explanations are written in both languages in index.html with
   L(english, hindi). Stock names, news headlines and some detailed
   research notes from the server stay in English.
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
    "Settings": "सेटिंग्स", "Light / dark": "लाइट / डार्क", "Sign out of Market Desk": "Market Desk से साइन आउट",
    "Used to size every trade plan": "हर ट्रेड योजना का आकार इसी से तय होता है", "Most you are willing to lose on one trade, as % of capital": "एक ट्रेड में आप पूंजी का अधिकतम कितना % खोने को तैयार हैं",
    "Phone alerts through your Telegram bot": "आपके टेलीग्राम बॉट से फ़ोन अलर्ट", "Browser notification + sound when a signal fires": "सिग्नल आने पर ब्राउज़र सूचना + आवाज़",
    "Research the universe again and replace today's list": "सभी शेयरों पर दोबारा रिसर्च करके आज की सूची बदलें", "NSE symbol or company — e.g. RELIANCE, TCS, HDFCBANK": "NSE सिंबल या कंपनी — जैसे RELIANCE, TCS, HDFCBANK",
    /* session phases (from the server) */
    "Weekend — showing the last session": "सप्ताहांत — पिछला सत्र दिखा रहे हैं", "Pre-market — provisional watchlist from yesterday's data": "प्री-मार्केट — कल के डेटा से अस्थायी वॉचलिस्ट",
    "Opening range forming — list locks at 9:25 AM": "शुरुआती दायरा बन रहा है — सूची 9:25 बजे लॉक होगी", "Market open — live signals": "बाज़ार खुला — लाइव सिग्नल",
    "Market closed — today's results": "बाज़ार बंद — आज के नतीजे",
  };

  /* phrases with numbers: full-text patterns */
  const HI_RX = [
    [/^weight (\d+)%$/, "भार $1%"], [/^· weight (\d+)%$/, "· भार $1%"], [/^(.+) · weight (\d+)%$/, "$1 · भार $2%"],
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
    [/^NSE (open|closed|Pre-market|Post-market|Weekend|Holiday) · (.+) IST$/, (m, a, b) => "NSE " + ({ open: "खुला", closed: "बंद", "Pre-market": "प्री-मार्केट", "Post-market": "बाज़ार के बाद", Weekend: "सप्ताहांत", Holiday: "छुट्टी" }[a] || a) + " · " + b + " IST"],
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
    for (const [rx, rep] of HI_RX) if (rx.test(t)) return s.replace(t, t.replace(rx, rep));
    let o = t, hit = false; for (const [rx, rep] of SUBS) if (rx.test(o)) { o = o.replace(rx, rep); hit = true; }
    return hit ? s.replace(t, o) : null;
  };
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
