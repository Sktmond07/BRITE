// complaint_keywords.js
// ============================================================
// ALL KEYWORD / PATTERN DATA for the KP Classifier
// Extracted from resident_complaint.js
// Load this BEFORE resident_complaint.js
// ============================================================

/* ============================================================
   SECTION A: THE 45 LAWS (barangayLaws)
   Used by: classifyDescription() → subject + title suggestion
   ============================================================ */
window.BARANGAY_LAWS = [
    {
        article: 'Art. 154', title: 'Unlawful Use of Means of Publication and Unlawful Utterances', victim: ['any'],
        keywords: [
            'unlawful publication','unlawful utterance','unlawful utterances',
            'unlawful use of means of publication','unlawful use of means',
            'ilegal na publication','ilegal na paglalathala','ilegal na pagsasalita',
            'ilegal na utterance','ilegal na salita','ilegal na pananalita',
            'masamang salita','masasamang salita','masamang pananalita',
            'masamang pagsasalita','masamang sinasabi',
            'paninirang puri sa social media','paninirang puri sa online',
            'paninirang puri sa internet','paninirang puri sa facebook',
            'paninirang puri sa fb','paninirang puri sa twitter',
            'post sa facebook','post sa fb','post sa social media',
            'post sa online','post sa internet',
            'nagpost','nag-post','nagpost sa','nagpost ng',
            'nag-comment','nagcomment','nagcomment sa','nagcomment ng',
            'comment sa facebook','comment sa fb','comment sa social media',
            'cyber','cyber libel','cyberlibel','cyber-libel',
            'online libel','online paninira','online paninirang puri',
            'libel sa online','libel sa social media','libel sa facebook',
            'libel sa fb','libel sa internet','libel online','naglibel',
            'unlawful','illegal utterance','illegal publication','illegal post',
            'nag-udyok','nag-udyok sa','nag-udyok ng','nag-uudyok',
            'nag-udyok sa iba','nag-udyok sa publiko','nang-udyok',
            'nanghihikayat','nanghikayat','humihikayat',
            'nagpapahayag','nagpahayag','nagbibigay pahayag',
            'pahayag','nagbigay pahayag','nagbigay ng pahayag'
        ]
    },
    {
        article: 'Art. 155', title: 'Alarms and Scandals', victim: ['any','public'],
        keywords: [
            'alarms','alarm','scandal','scandals','scandalous','alarm and scandal',
            'alarms and scandals','disturbance','public disturbance','public scandal',
            'public disorder','disorder','breach of peace','disturbing the peace',
            'iskandalo','nag-iskandalo','iskandaloso','iskandalosa',
            'pag-iingay','pag-iingay sa publiko',
            'ingay','maingay','napakaingay','sobrang ingay','masyadong maingay',
            'grabe ang ingay','ang ingay ng',
            'sigaw','sumigaw','nagsisigaw','sigawan','isigaw','sumisigaw',
            'sigaw sa publiko','sigaw sa kalye','sigaw sa kalsada',
            'gulo','nagkagulo','nagkakagulo','nagugulo','gulong-gulo',
            'gulo sa kalye','gulo sa kalsada','gulo sa publiko',
            'nagwawala','pumapalahaw','palahaw','nagsasayaw',
            'nag-iingay','nag-iingay sa','nag-iingay sa publiko',
            'videoke','videokehan','nagvivideoke','nag-videoke','videoke king',
            'nag-videoke ng','nag-videoke sa','nagvideoke','nagvideokehan',
            'karaoke','karaokehan','nagkakaraoke','nag-karaoke','karaoke king',
            'nag-karaoke ng','nag-karaoke sa','nagkakaraokehan',
            'malakas na musika','malakas na tugtog','malakas na tunog',
            'malakas na ingay','malakas ang musika',
            'nagpapatugtog','nagpapatugtog ng','nagpapatugtog sa','nagtutugtog',
            'nagpapamusika','nagpamusika','sound system','soundsystem',
            'sound trip','soundtrip','sounds','subwoofer','amplifier','speaker',
            'konstruksyon','construction','construction noise','construction site',
            'party','handaan','kasiyahan','kasiyahan na maingay','handaan na maingay',
            'nag-iingay sa gabi','nag-iingay sa madaling araw',
            'gabi-gabi na ingay','gabi-gabing ingay','gabi-gabi na maingay',
            'sumisigaw sa gabi','sumisigaw sa madaling araw',
            'umaangal','siren','sirena','tumutunog',
            'all-night','all night','buong gabi','buong magdamag',
            'aabot ng gabi','hanggang gabi','hanggang madaling araw'
        ]
    },
    {
        article: 'Art. 175', title: 'Using False Certificates', victim: ['any'],
        keywords: [
            'false certificate','falsified certificate','fake certificate',
            'forged certificate','forgery','forging','forged',
            'falsification','falsified','falsify','falsifying',
            'falsification of document','falsification of public document',
            'falsification of private document','forged signature','forged document',
            'forged paper','forged id','falsified document','falsified paper','falsified id',
            'pekeng certificate','pekeng sertipiko','peke ang certificate',
            'peke ang sertipiko','pekeng dokumento','pekeng mga dokumento',
            'peke','pekeng','peke-peke','gawa-gawa','gawa-gawang',
            'pekeng papel','pekeng papeles','peke ang papel','peke ang papeles',
            'pekeng ID','peke ang ID','peke ID','pekeng identification',
            'pekeng lisensya','peke ang lisensya','pekeng permit','peke ang permit',
            'pekeng barangay certificate','pekeng barangay clearance',
            'pekeng barangay permit','pekeng police clearance',
            'pekeng nbi clearance','pekeng police',
            'peke ang pangalan','pekeng pangalan','nagpeke','nagpeke ng',
            'nagpeke ng certificate','nagpeke ng dokumento',
            'gumawa ng pekeng','gumawa ng peke','gumawa-gawa ng',
            'gumagawa ng pekeng','gumagawa ng peke',
            'gawa-gawang certificate','gawa-gawang sertipiko',
            'gawa-gawang dokumento','gawa-gawang papel',
            'peke ang lagda','pekeng lagda','peke ang pirma','pekeng pirma'
        ]
    },
    {
        article: 'Art. 178', title: 'Using Fictitious Names and Concealing True Names', victim: ['any'],
        keywords: [
            'fictitious name','fictitious names','fictitious','concealing true name',
            'concealing true names','concealing name','concealing identity',
            'concealing','concealed','concealment','false name','false names',
            'fake name','fake names','alias','aliases','using alias','uses alias',
            'pekeng pangalan','peke ang pangalan','pekeng mga pangalan',
            'peke ang mga pangalan','hindi tunay na pangalan','hindi totoong pangalan',
            'hindi tunay na pagkatao','hindi totoong pagkatao',
            'hindi tunay na identity','hindi totoong identity',
            'nagpanggap','nagpapanggap','nagpapanggap na','nagpanggap na',
            'nagpapanggap bilang','nagpanggap bilang',
            'nagpapanggap na iba','nagpanggap na iba','ibang tao','ibang pangalan',
            'peke ang identity','peke ang pagkatao','peke ang pangalan',
            'nagtatago ng tunay na pangalan','nagtatago ng tunay na pagkatao',
            'nagtatago ng pangalan','itinatago ang pangalan','itinago ang pangalan',
            'peke ang pagpapakilala','nagpakilala ng pekeng','nagpakilala ng peke',
            'nagpakilala bilang','nagpapakilala bilang',
            'nagpanggap na doktor','nagpanggap na abogado','nagpanggap na pulis',
            'nagpanggap na barangay','nagpanggap na tanod','nagpanggap na guro'
        ]
    },
    {
        article: 'Art. 179', title: 'Illegal Use of Uniforms and Insignias', victim: ['any'],
        keywords: [
            'illegal use of uniforms','illegal use of insignias',
            'illegal use of uniform','illegal use of insignia',
            'illegal uniform','illegal insignia','illegal uniforms','illegal insignias',
            'pekeng uniporme','peke ang uniporme','pekeng insignia','peke ang insignia',
            'pekeng uniform','peke ang uniform','pekeng badge','peke ang badge',
            'pekeng barong','peke ang barong','pekeng uniforme','peke ang uniforme',
            'nagpanggap na tanod','nagpanggap na barangay tanod',
            'nagpanggap na pulis','nagpanggap na police',
            'nagpanggap na barangay official','nagpanggap na opisyal',
            'nagpanggap na opisyal ng barangay','nagpanggap na kapitan',
            'nagpanggap na barangay captain','nagpanggap na konsehal',
            'nagpanggap na mayor','nagpanggap na gobernador',
            'nagpapanggap na tanod','nagpapanggap na pulis',
            'nagpapanggap na opisyal','nagpapanggap na kapitan',
            'uniporme ng barangay','uniform ng barangay','uniporme ng tanod',
            'uniform ng tanod','uniporme ng pulis','uniform ng pulis',
            'insignia','badge','barangay badge','police badge','tanod badge',
            'nagpeke ng uniporme','nagpeke ng badge','nagpeke ng insignia',
            'gumawa ng pekeng uniporme','gumawa ng pekeng badge'
        ]
    },
    {
        article: 'Art. 252', title: 'Physical Injuries Inflicted in a Tumultuous Affray', victim: ['any'],
        keywords: [
            'tumultuous affray','tumultuous','affray','tumult','tumults',
            'fracas','commotion','melee','brawl','brawling','brawled','fighting',
            'altercation','fight','chaos','chaotic',
            'rambol','rumble','nag-rambol','nagrarambol','rumble sa',
            'malaking away','malalaking away','malaking gulo','malaking kaguluhan',
            'gulo','nagkagulo','nagkakagulo','kaguluhan',
            'away','nag-away','nag-aaway','away ng','away sa','away sa kalye',
            'nagbugbugan','nagbubugbugan','nagbugbog','nagbubugbog',
            'nagbasagan','nagbabasagan','nagbasag','nagbabasag',
            'nagsasakitan','nagsasaktan','nagsaktan','nagkasakitan',
            'nagbabarilan','nagbarilan','nagbaril','nagbabaril',
            'nagsasaksakan','nagsaksakan','nagsaksak','nagsasaksak',
            'gulo na may sugat','gulo na may nasaktan','gulo na may pinsala',
            'away na may sugat','away na may nasaktan','away na may pinsala',
            'nagkasugatan','nagkasakitan','nagkasugat',
            'nagsugatan','nagsusugatan','sugat','sugatan','nasugatan',
            'nasaktan','nasasaktan',
            'nangdamay','nangdamay sa','nadamay','nadadamay','nadamay sa',
            'nadamay ng','nadadamay ng','nasangkot','nasasangkot','nasangkot sa'
        ]
    },
    {
        article: 'Art. 253', title: 'Giving Assistance to Consummated Suicide', victim: ['any'],
        keywords: [
            'assistance to suicide','assistance to consummated suicide',
            'giving assistance to suicide','giving assistance',
            'helping suicide','helping to suicide','helping to commit suicide',
            'helping commit suicide',
            'suicide','suicides','suicidal','commit suicide','commits suicide',
            'committing suicide','committed suicide','suicide attempt',
            'suicide attempts','attempted suicide','attempting suicide',
            'tulong sa pagpapakamatay','tulong sa pagpapatiwakal',
            'tumulong sa pagpapakamatay','tumulong sa pagpapatiwakal',
            'pagtulong sa pagpapakamatay','pagtulong sa pagpapatiwakal',
            'pagpapakamatay','pagpapatiwakal','nagpakamatay','nagpatiwakal',
            'nagpapakamatay','nagpapatiwakal','magpapakamatay','magpapatiwakal',
            'pumatay sa sarili','pumapatay sa sarili','papatay sa sarili',
            'pagpatay sa sarili',
            'nag-udyok','nag-uudyok','nag-udyok sa','umudyok','umudyok sa',
            'nang-udyok','nang-uudyok',
            'nag-udyok magpakamatay','nag-udyok magpatiwakal',
            'humikayat','humihikayat','nanghikayat','nanghihikayat',
            'humikayat magpakamatay','humikayat magpatiwakal',
            'nagbigay ng tulong','nagbigay ng tulong sa pagpapakamatay'
        ]
    },
    {
        article: 'Art. 260', title: 'Responsibility of Participants in a Duel', victim: ['any'],
        keywords: [
            'duel','duels','dueling','dueled','duellist','duelist',
            'responsibility of participants','participants in a duel',
            'responsibility of participants in a duel',
            'if only physical injuries are inflicted',
            'no physical injuries have been inflicted',
            'duwelo','nagduwelo','nagduduwelo','duwelo sa','duwelo ng',
            'labanan','naglaban','naglalaban','lalaban','lalabanan',
            'laban','naglabanan','labana',
            'hamon','naghamon','nanghamon','hinamon','hahamon','humamon',
            'hamon sa laban','hamon sa away','hamon sa duwelo','hamon ng away',
            'naghamon ng away','naghamon ng laban','naghamon ng duwelo',
            'sagupa','nagsagupa','nagsasagupa','sagupaan',
            'nagsagupaan','nagsasagupaan','nagsagupa sa','nagkasagupa',
            'nagkasagupaan','nagkakasagupa',
            'dula','nagdula','nagdudula','dulaan',
            'pinagduwelo','pinaglaban','pinaglalaban','pinaglabanan',
            'hamunan','hamunan sa','hamunan ng','naghamunan'
        ]
    },
    {
        article: 'Art. 265', title: 'Less Serious Physical Injuries', victim: ['any'],
        keywords: [
            'less serious physical injuries','less serious physical injury',
            'less serious injuries','less serious injury','less serious',
            'serious physical injuries','serious physical injury',
            'serious injuries','serious injury','serious',
            'seryosong sugat','seryosong mga sugat','malubhang sugat',
            'malubhang mga sugat','malubhang pinsala','malubhang mga pinsala',
            'malubhang pinsala sa katawan','seryosong pinsala',
            'seryosong pinsala sa katawan','seryosong mga pinsala',
            'sugat na malala','sugat na seryoso','sugat na malubha',
            'malubhang sugat','malalang sugat','malalang mga sugat',
            'malalang pinsala','malalang mga pinsala',
            'sinaktan ng malubha','malubhang sinaktan','sinaktan ng matindi',
            'binugbog ng matindi','binugbog ng malubha','sinaktan ng grabe',
            'nasugatan ng malubha','nasugatan ng matindi',
            'naospital dahil sa pananakit','naospital dahil sa pambubugbog',
            'nasugatan','nasusugatan','sugatan','sinugatan','sinusugatan',
            'nasaktan','nasasaktan','sinaktan','sinasaktan','nasaktan ng',
            'sinaktan ng','pinagbabugbog','pinagbubugbog','binugbog','binubugbog',
            'ginulpi','ginugulpi','pinagulpi','pinagugulpi',
            'nagulpi','nagugulpi','bugbog','bugbugin',
            'pasa','pasa-pasa','may pasa','puno ng pasa','maraming pasa',
            'namaga','namamaga','pamamaga','nagmaga','nagmamaga',
            'dumugo','dumudugo','dinugo','dinudugo','nagdugo','nagdurugo',
            'pagdurugo','pagdudugo',
            'sugat sa ulo','sugat sa mukha','sugat sa braso','sugat sa kamay',
            'sugat sa binti','sugat sa paa','sugat sa likod','sugat sa tiyan',
            'bali','nabali','nabalian','binabali','babaliin','baliin',
            'bali ang','bali-bali','bali sa','bali ng','nabalian ng buto',
            'napilay','napipilay','mapipilay','pilay','pinapilay',
            'ospital','naospital','nagpaospital','ipinadala sa ospital',
            'medical treatment','medical attention','medical help',
            'nagpagamot','nagpapagamot','pagpapagamot',
            'nagpatingin','nagpapatingin','pagpapatingin','patingin sa doktor',
            'patingin sa ospital'
        ]
    },
    {
        article: 'Art. 266', title: 'Slight Physical Injuries and Maltreatment', victim: ['any','minor','senior','woman','pwd'],
        keywords: [
            'slight physical injuries','slight physical injury','slight injuries',
            'slight injury','slight','minor injuries','minor injury','minor',
            'maltreatment','maltreat','maltreating','maltreated','maltreats',
            'magaan na sugat','magaan na mga sugat','magaan na pinsala',
            'maliit na sugat','maliit na mga sugat','maliit na pinsala',
            'magaan na pinsala sa katawan','maliit na pinsala sa katawan',
            'suntok','sinuntok','sinusuntok','nasuntok','nasusuntok',
            'suntukin','suntukin ka','suntukin kita','suntukin nila',
            'sipa','sinipa','sinisipa','nasipa','sipain',
            'sampal','sinampal','sinasampal','nasampal','sampalin',
            'palo','pinalo','pinapalo','napalo','paluin',
            'paluin kita','palo ng','palo sa','binatukan','binabatukan',
            'natukan','batukan','batukan ka','batukan kita',
            'tulak','tinulak','tinutulak','natulak','tulakin',
            'tulakin ka','tulakin kita','tulak sa','tulak ng',
            'sabunot','sinabunutan','sinasabunutan','nasabunutan',
            'sabunutan','sabunutan ka','sabunutan kita','hinila ang buhok',
            'hinihila ang buhok','hinila','hinihila','hinatak','hinahatak',
            'kalmot','kinalmot','kinakalmot','nakalmot','kalmutin',
            'kalmutin ka','kalmutin kita','kalmot sa','kalmot ng',
            'kagat','kinagat','kinakagat','nakagat','kagatin',
            'kagatin ka','kagatin kita','kagat sa','kagat ng',
            'pagmamalupit','nagmamalupit','nangmamalupit','namamalupit',
            'pinagmamalupitan','pinagmalupitan','namalupit','namamamalupit',
            'pananakit','nananakit','nanakit','sinaktan','sinasaktan',
            'pananampal','nanampal','nananampal',
            'pambubugbog','nambubugbog','bumabugbog','binubugbog',
            'panunulak','nangunulak','tumutulak','tinutulak',
            'pananabunot','nangangabunot','sumasabunot','sinasabunot',
            'pananakit sa','pananakit ng','pananakit sa katawan','pananakit sa kapwa',
            'sinaktan ang bata','sinaktan ang menor','sinaktan ang anak',
            'binubugbog ang bata','binubugbog ang menor','binubugbog ang anak',
            'pinapalo ang bata','pinapalo ang menor','pinapalo ang anak',
            'sinasaktan ang bata','sinasaktan ang menor','sinasaktan ang anak'
        ]
    },
    {
        article: 'Art. 269', title: 'Unlawful Arrest', victim: ['any'],
        keywords: [
            'unlawful arrest','unlawful arrests','illegal arrest','illegal arrests',
            'false arrest','false arrests','wrongful arrest','wrongful arrests',
            'unlawful detention','unlawful detentions','illegal detention',
            'illegal detentions','unlawful imprisonment','illegal imprisonment',
            'illegal na pag-aresto','illegal na huli',
            'ilegal na pag-aresto','ilegal na huli','ilegal na paghuli',
            'illegal na pagkakulong','ilegal na pagkakulong',
            'kinulong','kinukulong','nagkulong','nagkukulong','ikulong',
            'ikukulong','ipinakulong','ipinapakulong','pinakulong','pinapakulong',
            'kinulong ng walang kaso','kinulong ng walang','kinulong ng',
            'hinuli','hinuhuli','naghuli','naghuhuli','hulihin','huhulihin',
            'hinuli ng walang warrant','hinuli ng walang kaso','hinuli ng walang',
            'detained','detain','detaining','detains','detention','detentions',
            'nakakulong','nakakulong sa','nakakulong kami',
            'nakakulong ako','nakakulong siya','nakakulong sa presinto',
            'kulong','naka-kulong','naka-kulong sa','naka-kulong sa presinto',
            'presinto','presinto ng','dinala sa presinto','dinala sa',
            'dinala sa kulungan','dinala sa bilangguan',
            'preso','preso sa','naging preso','presong','preso na',
            'walang warrant','walang warrant of arrest','walang warrant ng',
            'walang kaso','walang kasong','walang kaso laban',
            'walang kaso sa','walang kaso siya','walang kaso ako',
            'walang basehan','walang basehan ang','walang basehan ang pag-aresto',
            'walang basehan ang paghuli','walang basehan ang pagkakulong',
            'walang dahilan','walang dahilan ang','walang dahilan ang pag-aresto',
            'walang dahilan ang paghuli','walang dahilan ang pagkakulong',
            'hinuli nang walang','hinuli nang walang dahilan',
            'hinuli nang walang kaso','hinuli nang walang warrant',
            'kinulong nang walang','kinulong nang walang dahilan',
            'kinulong nang walang kaso','kinulong nang walang warrant'
        ]
    },
    {
        article: 'Art. 271', title: 'Inducing a Minor to Abandon His / Her Home', victim: ['minor'],
        keywords: [
            'inducing minor','inducing a minor','inducing minors','inducing',
            'induce minor','induce a minor','induce minors','inducement of minor',
            'inducing a minor to abandon home','inducing minor to abandon home',
            'inudyukan ang bata','inudyukan ang menor','inudyukan ang menor de edad',
            'inudyukan ang batang','inudyukan si',
            'inudyukan ang','inudyukan','nag-udyok','nag-uudyok','umudyok',
            'nang-udyok','nang-uudyok','nangudyok','nangungudyok',
            'bata tumakas','batang tumakas','batang tumatakas','tumakas na bata',
            'batang tumakbo','batang tumatakbo','tumakbo na bata','tumakbo',
            'batang umalis ng bahay','batang umaalis ng bahay','umalis ng bahay',
            'batang lumayas','batang lumalayas','lumayas na bata','lumayas',
            'minor na umalis','minor na lumayas','minor na tumakas',
            'naglayas na bata','batang naglayas','naglalayas na bata',
            'naglalayas','naglayas','naglayas si','naglayas ang',
            'inudyukan maglayas','tinulungan maglayas','tinulungan tumakas',
            'tinulungan umalis','tinulungan lumayas',
            'hinikayat maglayas','hinikayat tumakas','hinikayat umalis','hinikayat lumayas'
        ]
    },
    {
        article: 'Art. 275', title: 'Abandonment of a Person in Danger', victim: ['any'],
        keywords: [
            'abandonment','abandonments','abandon','abandons','abandoning',
            'abandoned','abandonment of person','abandonment of person in danger',
            'abandonment of own victim','abandonment of victim',
            'abandonment of a person in danger',
            'abandonment of one\'s own victim',
            'iniwan','iniwanan','ini-iwan','nag-iwan','nagiwan','iiwan','iiwanan',
            'iniwan sa panganib','iniwan sa peligro','iniwan sa sakuna',
            'iniwan sa aksidente','iniwan sa gulo','iniwan sa away',
            'iniwan sa sunog','iniwan sa baha','iniwan sa oras ng panganib',
            'pinabayaan','pinababayaan','nagpabaya','nagpapabaya','pabayaan',
            'pinabayaan sa panganib','pinabayaan sa peligro','pinabayaan sa sakuna',
            'pinabayaan sa aksidente','pinabayaan sa gulo','pinabayaan sa away',
            'hindi tinulungan','hindi tinutulungan','hindi tumulong',
            'hindi tumutulong','hindi sinaklolohan','hindi sinasaklolohan',
            'hindi sinagip','hindi sinasagip','hindi iniligtas','hindi inililigtas',
            'inabandona','inabandona sa','inabandona ang','inabandona kami',
            'inabandona ako','inabandona siya',
            'iniiwan','iniiwan sa','iniiwan ang','iniiwan kami','iniiwan ako',
            'pabaya','pabaya sa','pabayang','pabaya siya','pabaya ako','pabaya kami',
            'walang pakialam','walang pakialam sa','walang pakialam sa panganib',
            'walang pakialam sa peligro','walang pakialam sa sakuna',
            'walang pakialam sa kapwa','walang malasakit','walang malasakit sa'
        ]
    },
    {
        article: 'Art. 276', title: 'Abandoning a Minor (Under 7 Years Old)', victim: ['minor'],
        keywords: [
            'abandoning minor','abandoning a minor','abandoning minors',
            'abandoning a child','abandoning children','abandoning kid',
            'abandoning kids','abandoning baby','abandoning babies',
            'abandoning a minor child','abandoning minor child',
            'child under seven','child under 7','under seven years old',
            'under 7 years old','minor under seven','minor under 7',
            'iniwang bata','iniwang batang','iniwang sanggol','iniwang baby',
            'iniwang anak','iniwang mga bata','iniwang mga sanggol',
            'pinabayaang bata','pinabayaang batang','pinabayaang sanggol',
            'pinabayaang baby','pinabayaang anak','pinabayaang mga bata',
            'inabandonang bata','inabandonang sanggol','inabandonang baby',
            'inabandonang anak','inabandonang mga bata',
            'bata iniwan','bata iniwanan','sanggol iniwan','sanggol iniwanan',
            'baby iniwan','baby iniwanan','anak iniwan','anak iniwanan',
            'bata wala pang 7','batang wala pang 7','sanggol wala pang 7',
            'bata wala pang pitong','batang wala pang pitong',
            'bata na wala pang 7 taon','bata na wala pang pitong taon',
            'batang pinabayaan','sanggol na pinabayaan','sanggol na inabandona',
            'inabandona ang anak','inabandona ang bata','inabandona ang sanggol',
            'inabandona ang baby','inabandona ang mga anak',
            'iniwan ang anak','iniwan ang bata','iniwan ang sanggol',
            'iniwan ang baby','iniwan ang mga anak',
            'pinabayaan ang anak','pinabayaan ang bata','pinabayaan ang sanggol',
            'pinabayaan ang baby','pinabayaan ang mga anak',
            'iniwan sa kalye','iniwan sa lansangan','iniwan sa daan',
            'iniwan sa labas','iniwan sa bahay','iniwan sa pintuan',
            'iniwan sa gate','iniwan sa simbahan','iniwan sa ospital',
            'iniwan sa kanal','iniwan sa basurahan','iniwan sa sasakyan',
            'iniwan sa kotse','iniwan sa tricycle','iniwan sa jeep'
        ]
    },
    {
        article: 'Art. 277', title: 'Abandonment of a Minor by Persons Entrusted with Custody; Indifference of Parents', victim: ['minor'],
        keywords: [
            'abandonment by custodian','abandonment by person',
            'abandonment by person entrusted','abandonment of minor',
            'abandonment of minor by custodian','abandonment of minor by parent',
            'indifference of parents','indifference of parent',
            'persons entrusted with custody','entrusted with his custody',
            'entrusted with her custody','entrusted with custody',
            'iniwan ng magulang','iniwan ng ina','iniwan ng ama',
            'pinabayaan ng magulang','pinabayaan ng ina','pinabayaan ng ama',
            'inabandona ng magulang','inabandona ng ina','inabandona ng ama',
            'pabayang magulang','pabayang ina','pabayang ama',
            'hindi inaalagaan','hindi inaalagaan ng magulang',
            'hindi pinapakain','hindi pinapakain ng magulang',
            'hindi pinapaligo','hindi pinapasok sa eskwela',
            'hindi pinapaaral','nagpapabaya','nagpapabaya ng anak',
            'walang pakialam','walang pakialam sa anak','walang pakialam sa bata'
        ]
    },
    {
        article: 'Art. 280', title: 'Qualified Trespass to Dwelling', victim: ['property'],
        keywords: [
            'qualified trespass','qualified trespasses','qualified trespass to dwelling',
            'qualified trespass to dwelling without the use of violence',
            'trespass','trespasses','trespassing','trespassed','trespasser',
            'trespassers','trespass to dwelling','trespass dwelling',
            'without use of violence','without violence','without intimidation',
            'pumasok sa bahay','pumasok sa loob ng bahay','pumasok sa loob',
            'pinasok ang bahay','pinasok ang loob ng bahay','pinasok ang loob',
            'pumasok nang walang pahintulot','pumasok nang walang paalam',
            'pasok sa bahay','pasok sa loob','pasok sa loob ng bahay',
            'loob ng bahay','loob ng bahay namin','loob ng bahay nila',
            'pumasok sa bakuran','pinasok ang bakuran',
            'nagbabalak pumasok','nagpupumilit pumasok',
            'pilit pumasok','pinilit pumasok'
        ]
    },
    {
        article: 'Art. 281', title: 'Other Forms of Trespass', victim: ['property'],
        keywords: [
            'other trespass','other trespasses','other forms of trespass',
            'trespass to property','trespass property','trespassing property',
            'trespass sa lupa','trespass sa bakuran','trespass sa property',
            'pumasok sa lupa','pinasok ang lupa','pumasok sa bakuran','pinasok ang bakuran',
            'pumasok sa property','pinasok ang property',
            'walang paalam na pumasok','sumalakay sa bakuran','sumalakay sa lupa',
            'nagpupumilit pumasok sa lupa','pilit pumasok sa lupa',
            'pinilit pumasok sa lupa','trespassing sa property',
            'trespassing','trespasser','trespassers','nagtrespass'
        ]
    },
    {
        article: 'Art. 283', title: 'Light Threats', victim: ['any'],
        keywords: [
            'light threats','light threat','magaan na banta','magaan na pananakot',
            'banta','nagbanta','nagbabanta','babanta','pagbabanta','pagbanta',
            'bantaan','binantaan','binabantaan','nambanta','nananakot',
            'mananakot','nanakot','tinakot','tinatakot','pananakot','panakot',
            'pangamba','pangambahan','nangamba','nangangamba',
            'nagbantang sasaktan','nagbantang papatayin',
            'nagbantang sasaksakin','nagbantang babarilin',
            'nagbantang susunugin','nagbantang sisiraan','nagbantang ipapahiya',
            'banta ng away','banta ng gulo','banta ng laban','banta ng suntukan',
            'pinagbantaan','pinagbabantaan',
            'pangamba sa buhay','pangamba sa kaligtasan','pangamba sa pamilya'
        ]
    },
    {
        article: 'Art. 285', title: 'Other Light Threats', victim: ['any'],
        keywords: [
            'other light threats','other light threat','ibang magaan na banta',
            'banta sa sulat','banta sa letter','banta sa text','banta sa message',
            'banta sa chat','banta sa messenger','banta sa sms',
            'banta sa social media','banta sa facebook','banta sa fb',
            'banta sa twitter','banta sa tiktok','banta sa instagram',
            'nagbanta sa sulat','nagbanta sa text','nagbanta sa message',
            'nagbanta sa social media','nagbanta sa facebook',
            'banta via text','banta via chat','banta via sms','banta via email',
            'banta sa pamamagitan ng','banta gamit ang'
        ]
    },
    {
        article: 'Art. 286', title: 'Grave Coercion', victim: ['any'],
        keywords: [
            'grave coercion','grave coercions','mabigat na pananakot',
            'sapilitan','sinasapilitan','pinipilit','pinilit','pinipilit gawin',
            'pinilit gawin','pinipilit ako','pinilit ako',
            'pinipilit pumayag','pinilit pumayag','pinipilit sumama',
            'pinilit sumama','tinutukan','tinututukan','itinutok','itinututok',
            'tutukan','tutukan ng baril','tinutukan ng baril',
            'tutukan ng patalim','tinutukan ng patalim',
            'tutukan ng kutsilyo','tinutukan ng kutsilyo',
            'tinakot para','tinakot para gawin','tinakot para pumayag',
            'pinilit na','pinilit na gawin','pinilit na pumayag','pinilit na sumama',
            'pinilit na magbigay','pinilit na magbayad','pinilit na pumirma',
            'sapilitang pagbibigay','sapilitang pagkuha','sapilitang paggawa'
        ]
    },
    {
        article: 'Art. 287', title: 'Light Coercion', victim: ['any'],
        keywords: [
            'light coercion','light coercions','magaan na pananakot',
            'magaan na pagpilit','magaan na panghihimasok',
            'pinipilit','pinilit','sapilitan','sinasapilitan','pinapilit',
            'pinapagawa','pinapasunod',
            'pinapasunod sa','pinapasunod ako','pinapasunod ka',
            'pinapagawa ng','pinapagawa ako','pinapagawa ka',
            'pilitin','pipilitin','pinipilit','pinilit',
            'pipilitin magbayad','pinipilit magbayad','pinilit magbayad',
            'pipilitin magbigay','pinipilit magbigay','pinilit magbigay',
            'pipilitin sumama','pinipilit sumama','pinilit sumama'
        ]
    },
    {
        article: 'Art. 288', title: 'Other Similar Coercions', victim: ['any'],
        keywords: [
            'compulsory purchase','compulsory purchases','compulsory buying',
            'other similar coercions','other coercions',
            'sapilitang pagbili','sapilitang pagbili ng merchandise',
            'sapilitang pagbabayad','sapilitang bayad',
            'pinilit bumili','pinipilit bumili','pinabayaran','pinapabayaran',
            'token','tokens','token payment','token system',
            'bayad sa token','bayad sa tokens',
            'pagbabayad ng sahod sa token','sahod na token',
            'compulsory merchandise','compulsory wage','payment by tokens'
        ]
    },
    {
        article: 'Art. 289', title: 'Formation, Maintenance and Prohibition of Combination of Capital or Labor through Violence or Threats', victim: ['any'],
        keywords: [
            'combination of capital','combination of capital or labor',
            'formation of combination','labor combination','capital combination',
            'union formation','maintenance of combination','prohibition of combination',
            'through violence','through threats','through threat',
            'sapilitang unyon','unyon','union','unions','labor union','labor unions',
            'sapilitang pagsali','sapilitang pagsali sa unyon',
            'pinilit sumali','pinipilit sumali',
            'pagbuo ng unyon','pagbuo ng asosasyon','pagbuo ng kapisanan',
            'asosasyon','association','kapisanan','samahan','organisasyon',
            'organization','grupo','group','groups',
            'sapilitang pagbuo','sapilitang pagbuo ng'
        ]
    },
    {
        article: 'Art. 290', title: 'Discovering Secrets through Seizure and Correspondence', victim: ['any'],
        keywords: [
            'discovering secrets','discovering secret','seizure of correspondence',
            'seizure and correspondence','seizure of letters','seizure of mail',
            'binuksan ang liham','binuksan ang sulat','binuksan ang email',
            'binuksan ang mail','binuksan ang letter','binuksan ang sobre',
            'kinuha ang liham','kinuha ang sulat','kinuha ang email',
            'ninakaw ang liham','ninakaw ang sulat','ninakaw ang email',
            'pinagbubuksan ang sulat','pinagbubuksan ang liham',
            'binabasa ang liham','binabasa ang sulat','binabasa ang email',
            'nagbukas ng liham','nagbukas ng sulat','nagbukas ng email'
        ]
    },
    {
        article: 'Art. 291', title: 'Revealing Secrets with Abuse of Authority', victim: ['any'],
        keywords: [
            'revealing secrets','revealing secret','revealing secrets with abuse',
            'revealing secrets with abuse of authority','abuse of authority',
            'abuse of power','abuse of position','abuse of office',
            'isinumbong ang sikreto','isinumbong ang sekreto',
            'isiniwalat ang sikreto','isiniwalat ang sekreto',
            'inihayag ang sekreto','inihayag ang sikreto',
            'opisyal na nagsiwalat','opisyal na nagsumbong',
            'abuso ng kapangyarihan','inabuso ang kapangyarihan',
            'inabuso ang posisyon','inabuso ang puwesto',
            'inabuso ang tungkulin','inabuso ang',
            'nag-abuso','nag-abuso ng','nag-abuso ng kapangyarihan',
            'inabuso','inabuso ng',
            'nagsiwalat','nagsisiwalat','nagsiwalat ng',
            'nagsumbong','nagsusumbong','nagsumbong ng'
        ]
    },
    {
        article: 'Art. 309', title: 'Theft (Value ≤ P50)', victim: ['any','property'],
        keywords: [
            'theft','thefts','thief','thieves','stealing','steal','steals','stole','stolen',
            'nakaw','nagnakaw','nanakaw','ninakaw','ninakawan','ninanakaw',
            'pagnanakaw','nagnanakaw','magnanakaw','mangnanakaw',
            'nanakawin','nananakaw','nanakaw','nakawin','nakuha','kinuha',
            'kinukuha','kumuha','kumukuha','kinukuha ng walang paalam',
            'kinuha ng walang paalam','kinuha nang patago','kinuha nang tahimik',
            'kinuha ang wallet','kinuha ang cellphone','kinuha ang bag',
            'kinuha ang pitaka','kinuha ang pera','kinuha ang alahas',
            'nanakaw ang wallet','nanakaw ang cellphone',
            'nanakaw ang bag','nanakaw ang pitaka','nanakaw ang pera',
            'magnanakaw','mandurukot','nadukutan','nanakawan',
            'nadukutan ng','nadukutan ako','nadukutan kami','nadukutan siya',
            'dukot','dukutin','dinukot','dinudukot','nagdudukot',
            'pagnanakaw sa','pagnanakaw ng','pagnanakaw namin',
            'below 50','less than 50','50 piso','50 pesos','limampung piso',
            'kinuha ang gamit','nagnakaw ng gamit','nagnakaw sa bahay','pinasok at nagnakaw'
        ]
    },
    {
        article: 'Art. 310', title: 'Qualified Theft (Value ≤ P500)', victim: ['any','property'],
        keywords: [
            'qualified theft','qualified thefts','mabigat na pagnanakaw',
            'qualified','grave theft','aggravated theft',
            'pinasok ang bahay','pumasok sa bahay',
            'akyat bahay','akyat-bahay','nag-akyat bahay',
            'umakyat sa bahay','umakyat sa bubong','umakyat sa bintana',
            'pinasok ang bintana','pinasok ang pintuan',
            'sinira ang bintana at nagnakaw','sinira ang pinto at nagnakaw',
            'pinasok ang bahay at nagnakaw','pumasok sa bahay at nagnakaw',
            'pagnanakaw sa bahay','pagnanakaw na may pananakit',
            'below 500','less than 500','500 piso','500 pesos','limandaang piso'
        ]
    },
    {
        article: 'Art. 312', title: 'Occupation of Real Property or Usurpation of Real Rights', victim: ['property'],
        keywords: [
            'occupation of real property','occupation of real properties',
            'occupation of land','occupation of property','usurpation of real rights',
            'usurpation of real property','usurpation of real rights in property',
            'usurpation','usurp','usurps','usurping','usurped','usurper','usurpers',
            'sinakop ang lupa','sinakop ang property',
            'kinuha ang lupa','kinuha ang property',
            'inasamang lupa','inasamang property',
            'pagsakop ng lupa','pagsakop ng property',
            'nag-okupa ng lupa','nag-okupa ng property',
            'nag-occupy ng lupa','nag-occupy ng property',
            'inookupahan ang lupa','inookupahan ang property',
            'kinamkam ang lupa','kinamkam ang bahagi ng lupa',
            'nasakop ang lupa namin','nakuha ang bahagi ng lupa namin'
        ]
    },
    {
        article: 'Art. 313', title: 'Altering Boundaries or Landmarks', victim: ['property'],
        keywords: [
            'altering boundaries','altering boundary','altering landmarks',
            'altering landmark','altering of boundaries','altering of landmarks',
            'binago ang hangganan','binago ang mojon','binago ang bakod',
            'binago ang boundary','inilipat ang mojon','inilipat ang bakod',
            'inilipat ang boundary','inurong ang bakod','inurong ang boundary',
            'itinaas ang bakod','itinaas ang boundary',
            'pinalitan ang bakod','pinalitan ang boundary','pinalitan ang mojon',
            'nagpalit ng hangganan','nagpalit ng mojon','nagpalit ng bakod',
            'boundary','boundaries','hangganan','mojon','mojones',
            'landmark','landmarks','bakod','fence','fences'
        ]
    },
    {
        article: 'Art. 315', title: 'Swindling or Estafa (Amount ≤ P200)', victim: ['any'],
        keywords: [
            'swindling','swindlings','swindle','swindles','swindling or estafa',
            'swindling and estafa','estafa','estafas','estapador','estapadora',
            'estapadors','estapadoras','estafador','estafadora','estafadores',
            'nag-estafa','nag-estafa ng','nag-estafa sa',
            'niloko','niloloko','nanloko','nanloloko','manloloko',
            'dinaya','dinadaya','nangdaya','nangdaraya','mandaraya',
            'daya','pagdaya','pandaraya','pandarayang','nandaraya','nangdaraya',
            'panloloko','nanloloko','manloloko','panlilinlang','nanglilinlang',
            'niloko sa pera','niloko kami sa pera',
            'niloko sa negosyo','niloko sa transaksyon','niloko sa pagbili',
            'niloko sa pagbenta','niloko sa kontrata',
            'kinuha ang pera sa daya','kinuha ang bayad at tumakas',
            'nag-estafa ng pera','nag-estafa ng bayad',
            'nangdaya sa presyo','nangdaya sa sukli',
            'below 200','less than 200','200 piso','200 pesos','dalawandaang piso'
        ]
    },
    {
        article: 'Art. 316', title: 'Other Forms of Swindling', victim: ['any'],
        keywords: [
            'other swindling','other forms of swindling','other swindlings',
            'ibang panloloko','ibang uri ng panloloko','ibang panlilinlang',
            'ibang pandaraya','ibang uri ng pandaraya',
            'panloloko','panloloko sa','panloloko ng','nangloloko',
            'daya','pagdaya','pandaraya','pandarayang','nandaraya','nangdaraya',
            'niloko','niloloko','nanloko','nanloloko','manloloko',
            'nagdaya','nagdaya ng','nagdaya sa',
            'dinaya','dinadaya','dinaya sa',
            'pinagbentahan ng hindi totoo','nagbenta ng hindi totoo',
            'naglinlang','naglinlang ng','naglinlang sa',
            'nilinlang','nilinlang ng','nilinlang sa',
            'panlilinlang','nanglilinlang'
        ]
    },
    {
        article: 'Art. 317', title: 'Swindling a Minor', victim: ['minor'],
        keywords: [
            'swindling a minor','swindling minor','swindling minors',
            'swindling a child','swindling children','swindling a kid',
            'niloko ang bata','niloko ang menor','niloko ang menor de edad',
            'dinaya ang bata','dinaya ang menor','dinaya ang menor de edad',
            'panloloko sa bata','panloloko sa menor',
            'pandaraya sa bata','pandaraya sa menor',
            'bata na niloko','batang niloko','menor na niloko',
            'bata na dinaya','batang dinaya','menor na dinaya'
        ]
    },
    {
        article: 'Art. 318', title: 'Other Deceits', victim: ['any'],
        keywords: [
            'other deceits','other deceit','deceits','deceit','deceiving','deceive',
            'deceived','deceives','deceiver','deceivers',
            'ibang panlilinlang','ibang panloloko','ibang pandaraya',
            'ibang uri ng panlilinlang','ibang uri ng panloloko','ibang uri ng pandaraya',
            'panlilinlang','panloloko','pandaraya','daya','pagdaya','nagdaya',
            'nangdaya','nandaraya','nangdaraya','manlilinlang','manloloko',
            'naglinlang','nilinlang','nanglilinlang'
        ]
    },
    {
        article: 'Art. 319', title: 'Removal, Sale or Pledge of Mortgaged Property', victim: ['property'],
        keywords: [
            'removal','remove','removing','removed','sale','sell','selling','sold',
            'pledge','pledging','pledged','mortgaged','mortgage','mortgaging',
            'alis','inalis','nag-alis','mag-alis','pag-alis','umalis','inalisan',
            'benta','ibinenta','pinagbenta','nagbenta','magbenta','pagbenta','binenta',
            'binebenta','ibebenta','pinagbebenta','nakabenta',
            'sangla','sinangla','nakasanla','pinagsanglaan','nagsangla','magsangla',
            'pagsangla','isinasangla','isasangla','sanglaan','sanglahan',
            'nakamortgage','nakamortgaged','nakasanlang lupa','nakasanlang bahay',
            'walang pahintulot','walang paalam','hindi pinayagan',
            'inalis ang','kinuha ang','ibinenta ang','isinangla ang'
        ]
    },
    {
        article: 'Art. 328', title: 'Special Cases of Malicious Mischief (Value ≤ P1,000)', victim: ['property'],
        keywords: [
            'malicious mischief','mischief','special mischief','malicious',
            'special cases of malicious mischief',
            'sinira','sinisira','nasira','paninira','maninira','nagsira','magsira',
            'pagkasira','pagkakasira','nasisira','nasiraan','sira','sirang','sira-sira',
            'sira ng gamit','sirang gamit','sinira ang gamit',
            'binasag','binabasag','nagbasag','magbasag','pagbasag','basag','basagin',
            'binaboy','binabuy','nagbaboy','nambaboy','pambaboy',
            'ginulo','ginugulo','nanggulo','nanggugulo',
            'dinurog','dinudurog','nagdudurog',
            'pinutol','piniputol','nagputol','nagpuputol',
            'sinunog','sinusunog','nagsunog','nagsusunog','pagsunog',
            'damage','destroy','destroyed','destroying','vandalism','vandalize',
            'vandalized','vandalizing','ruin','ruined','ruining',
            'basag','wasak','nawasak','winasak','winawasak',
            'lansag','nilansag','giniba','ginigiba','naggiba','igiba',
            'punit','pinunit','pinupunit','napunit','napupunit'
        ]
    },
    {
        article: 'Art. 329', title: 'Other Mischiefs (Value ≤ P1,000)', victim: ['property'],
        keywords: [
            'other mischief','other mischiefs','ibang paninira','ibang uri ng paninira',
            'disturbance','nuisance','litter','littering','dump',
            'dumping','dumped','dumps','garbage','trash','rubbish','waste',
            'paninira','nakakasira','nakasira','nakakasira ng','nakakasama',
            'gulo','ginulo','nanggulo','nanggugulo','nakakagulo','nakakagambala',
            'nagulo','nagugulo','ginagambala','nakakagambala','isturbo','istorbo',
            'nakakaistorbo','nag-isturbo','nakakaabala','nakaabala',
            'tapon','tinapon','itapon','nagtapon','nagtatapon','nagtatapong',
            'pagtatapon','itinatapon','itapon ang','itinapon ang',
            'tapon ng basura','tapon ng dumi','nagtapon ng basura',
            'basura','basurahan','basurang','basurero','nagbasura','magbasura',
            'basura sa harap','basura sa tapat','basura sa bakuran','basura sa lupa',
            'basura sa kalsada','basura sa daan','basura sa kanal','basura sa ilog',
            'nagkakalat ng basura','nagkalat ng basura','nagkakalat','nagkalat',
            'nakakalat','nakakalat na','kalat','kalat-kalat','maruming','marumi',
            'nakakadumi','nagpaparumi','nagpaparumi ng','nagpaparumi sa',
            'dumi','duming','nadumi','nadudumihan','dinumihan','nagpapadumi',
            'pinagsasabuyan','nagsasaboy','nagsasabuyan','sinasabuyan',
            'binuhusan','binubuhusan','nagbuhos','nagbubuhos','nagbuhos ng',
            'binuhusan ng','binubuhusan ng','ibinuhos','ibinubuhos',
            'pinagbuhusan','pinagbubuhusan','binuhusan ng dumi','binuhusan ng basura',
            'below 1000','less than 1000','1000 piso','1k','1000 pesos'
        ]
    },
    {
        article: 'Art. 338', title: 'Simple Seduction', victim: ['woman'],
        keywords: [
            'simple seduction','seduction','seduce','seducing','seduced',
            'seducement','seductress','seduced a woman','seduced a girl',
            'nag-akit','umakit','akit','pang-akit','nang-akit','nag-aaakit',
            'inakit','inaakit','pag-akit','pag-aaakit','umaaakit',
            'pinangakuan','nangako','nangangako','nagpangako','pangako',
            'pangako ng kasal','pangakong kasal','pangako ng pag-ibig',
            'niloko sa pag-ibig','niloko sa pagmamahal','niloko ang',
            'relasyon','karelasyon','may relasyon','nagrelasyon','nakikipagrelasyon',
            'kalaguyo','kerida','kabits','kabit','kabitin','may kabit',
            'binuntis','nabuntis','nagbuntis','pinabuntis','nagpabuntis',
            'iniwan sa ere','iniwan na buntis','iniwang buntis','pinabayaan na buntis',
            'virgin','birhen','dalaga','binibini','dalagita',
            'hindi kasal','walang kasal','hindi pinakasalan','hindi pinapanagot'
        ]
    },
    {
        article: 'Art. 339', title: 'Acts of Lasciviousness with Consent', victim: ['any'],
        keywords: [
            'lasciviousness','lascivious','lascivious acts','lascivious conduct',
            'acts of lasciviousness','with consent','with consent of offended party',
            'indecent','indecency','obscene','obscenity','lewd','lewdness',
            'sexual','sexual act','sexual acts','molestation','molest',
            'molesting','molested','molester',
            'malaswa','malaswang','malaswang gawa','malaswang kilos',
            'malaswang hawak','malaswang hipo',
            'kalaswaan','nakakalaswa','nakakalaswang','masamang gawa',
            'hipo','hinipo','humipo','humihipo','hinihipo','nahipo','nahihipo',
            'hipuin','hinahawakan','hawak','hinawakan','humawak','humahawak',
            'masamang hawak','masamang hipo','masamang paghawak',
            'kamay sa','kamay sa dibdib','kamay sa hita','kamay sa katawan',
            'ginalaw','ginagalaw','ginagalaw-galaw','nanggagalaw',
            'hinimas','hinihimas','naghimas','naghihimas','himas',
            'sinipsip','sinisipsip','nagsipsip','nagsisipsip',
            'dinilaan','dinidilaan','nagdila','nagdilaan',
            'hinagkan','hinahagkan','naghagkan','naghahagkan','hagkan','halik',
            'hinalikan','hinahalikan','naghalik','naghahalik','halikan',
            'consent','may pahintulot','pinayagan','sinang-ayunan',
            'malaswang','malalaswa','kabastusan','binastos','binabastos',
            'nambabastos','pambabastos','pangbabastos'
        ]
    },
    {
        article: 'Art. 356', title: 'Threatening to Publish and Offer to Prevent Such Publication', victim: ['any'],
        keywords: [
            'threat to publish','threatening to publish','threaten','threatened',
            'threatening','threat','publish','publishing','published','publication',
            'offer to prevent publication','prevent publication',
            'libel','libelous','blackmail','blackmailing','blackmailed',
            'extortion','extorting','extort','extortionist','extortioner',
            'expose','exposing','exposed','expose you',
            'ibubunyag ang sikreto','ibubunyag ang lihim','ibubunyag ang nangyari',
            'ipapahiya ako sa social media','ipapahiya ako sa facebook',
            'ipapahiya ako sa publiko','ibubuking ako','isusumbong ako',
            'banta','nagbanta','nagbabanta','babanta','pagbabanta','pagbanta',
            'nambanta','nananakot','mananakot','nanakot','tinakot','tinatakot',
            'takot','kinatatakutan','pinagbantaan','pinagbabantaan',
            'pananakot','panakot','pambabanta','pagbabantang',
            'ibubunyag','ibinubunyag','ibunyag','ibinunyag','nagbunyag',
            'isusumbong','isinasumbong','isumbong','sinumbong','nagsumbong',
            'ipapahiya','ipinapahiya','ipahiya','pinahiya','nagpahiya',
            'ibubuking','ibinubuking','ibuking','binuking','nagbuking',
            'isisiwalat','isinasisiwalat','isiwalat','isiniwalat','nagsiwalat',
            'kung hindi ka magbayad','kung hindi ka magbigay',
            'kapalit ng pera','kapalit ng','pera kapalit',
            'banta ng','bantang','bantang ipapahiya','bantang ibubunyag'
        ]
    },
    {
        article: 'Art. 357', title: 'Prohibiting Publication of Acts Referred to in Official Proceedings', victim: ['any'],
        keywords: [
            'publication of official','publication of acts','official publication',
            'official proceedings','official proceeding','official record',
            'prohibiting publication','forbid publication','prevent publication',
            'barred from publishing','gagged','gag order','gag orders',
            'secret','secrecy','classified','confidential','confidentiality',
            'opisyal na pagdinig','opisyal na paglilitis','opisyal na record',
            'opisyal na dokumento','opisyal na papeles','opisyal na usapin',
            'pinagbawalan magsalita','pinagbawalan mag-ulat','pinagbawalan magsabi',
            'pinagbawalan magbahagi','pinagbawalan ikuwento','pinagbawalan sabihin',
            'pinaghigpitan','pinaghihigpitan','pinaghigpit','naghigpit',
            'sikreto','sikretong','isang sikreto','sikreto ng','sikreto sa',
            'tinatago','itinatago','nagtatago','nagtago','itinago',
            'ipinagbawal','ipinagbabawal','nagbawal','nagbabawal','pinagbawal',
            'prohibited','prohibiting','prohibit','forbidden','forbid','forbidding',
            'leak','leaked','leaking','leaker','leakage',
            'proceedings','hearing','hearings','trial','trials','session','sessions',
            'barangay hearing','barangay proceedings','barangay trial',
            'barangay session','barangay record','barangay document',
            'record of the case','case record','case file',
            'opisyal','official','awtoridad','authority','otorsidad'
        ]
    },
    {
        article: 'Art. 363', title: 'Incriminating Innocent Persons', victim: ['any'],
        keywords: [
            'incriminating','incriminate','incriminated','incriminating innocent',
            'incriminating innocent persons','incriminating innocent person',
            'false accusation','false accusations','falsely accusing',
            'false witness','false testimony','false charge','false charges',
            'blame','blaming','blamed','frame','framing','framed','frame-up',
            'bintang','nagbintang','nagbibintang','binintangan','binibintangan',
            'pagbintang','pagbibintang','ibinintang','ibinibintang',
            'paratang','nagparatang','nagpaparatang','pinagparatangan',
            'pinaparatangan','paratangan','paratangin','ipinaratang','ipinaparatang',
            'pinagbintangan','pinagbibintangan','pinagbintangan ng',
            'sinungaling','magsinungaling','nagsinungaling','pagsisinungaling',
            'hindi totoo','hindi tunay','hindi naman totoo','hindi naman tunay',
            'gawa-gawa','gawa-gawang','kathang-isip','kathang isip',
            'imbento','imbentong','iniimbento','nag-imbento','nag-iimbento',
            'hinabi','hinahabi','gawa-gawang kwento','gawa-gawang istorya',
            'sinisi','sinisisi','nagsisi','nagsisisi','pinagsisihan',
            'itinuro','itinuturo','nagturo','nagtuturo','tinuro','tinuturo',
            'kasinungalingan','mga kasinungalingan','kasinungalingang',
            'pagsisinungaling','pagsisinungalingan','magsisinungaling',
            'ibinintang','ibinabintang','ibintang','ibinintang sa'
        ]
    },
    {
        article: 'Art. 364', title: 'Intriguing Against Honor', victim: ['any'],
        keywords: [
            'intriguing','intrigue','intrigues','intriguing against honor',
            'gossip','gossiping','gossiped','gossiper','gossipers',
            'slander','slandering','slandered','slanderer','slanderous',
            'defamation','defame','defaming','defamed','defamatory',
            'insult','insulting','insulted','insults','insulter',
            'verbal abuse','cursing','curse',
            'intriga','nag-intriga','nag-iintriga','pag-intriga',
            'tsismis','tsismoso','tsismosa','nagtsitsismis','nagtsismis',
            'pinagtsitsismisan','pinagtsismisan','tsismisan','tsismisang',
            'chismis','chismoso','chismosa','nagchichismis','nagchismis',
            'pinagchichismisan','pinagchismisan','chismisan','chismisang',
            'marites','maritess','nagmamarites','nagmarites',
            'siraan','sinisiraan','nagsiraan','nagsisiraan','siraan ng',
            'paninirang puri','paninirang-puri','paninirang',
            'naninirang','maninirang','maninirang puri',
            'pinanirang','pinapanirang','paninirang puri',
            'reputasyon','reputation','dangal','karangalan','pangalan',
            'sinira ang pangalan','sinisira ang pangalan','nagsira ng pangalan',
            'sinisiraan ang','sinisiraan ako','sinisiraan kami',
            'nagkalat ng tsismis','nagkakalat ng tsismis','kinalat','kinakalat',
            'pinagkalat','pinagkakalat','ipinagkalat','ipinagkakalat',
            'kumalat','kumakalat','lumaganap','lumalaganap','nagkalat',
            'nagkakalat','naglalaganap','nagpalaganap','nagpapalaganap',
            'nilalait','panlalait','nanlalait','manlalait',
            'insulto','ininsulto','nang-insulto','nang-iinsulto','insultuhin',
            'mura','minura','minumura','mumurahin','pinagmumura','nagmumura',
            'murahin','pinagmumura','namumura',
            'bastos','binastos','binabastos','nambabastos',
            'pambabastos','pagbibigay-bastos','panlalait',
            'masamang salita','masasamang salita','masamang pananalita',
            'masakit na salita','masasakit na salita','nananakit ng salita',
            'pananakit ng salita','pananakit sa pamamagitan ng salita'
        ]
    },
    {
        article: 'BP 22', title: 'Issuing Checks Without Sufficient Funds', victim: ['any'],
        keywords: [
            'bounced check','bouncing check','bad check','bouncing checks',
            'bounced cheque','bad cheque','dishonored check','dishonored cheque',
            'insufficient funds','insufficient fund','insufficient',
            'no sufficient funds','without sufficient funds','no funds',
            'issuing checks without sufficient funds',
            'issuing checks','issuing check','issued check','issue ng check','issue ng tseke',
            'check','checks','cheque','cheques','tseke','mga tseke',
            'cheke','mga cheke','cheke na','tseke na','chekeng','tsekeng',
            'walang pondo','walang pondong','kulang ang pondo','kulang pondo',
            'walang laman','walang laman ang','walang lamang',
            'tumalbog','tumatalbog','tatalbog','patalbog','talbugin',
            'tumalbog na check','tumalbog na tseke','tumalbog na cheke',
            'bounced','bounce','bounces','bouncing','nag-bounce','bumalik',
            'nagbalik','ibinalik','ibinabalik','ibinalik ng bangko',
            'ibinabalik ng banko','ibinalik ng bank','returned check',
            'bank','banko','bangko','savings','checking account',
            'checking','checkbook','chequebook','checkbook account',
            'bayad sa check','bayad sa tseke','bayad sa cheke',
            'check na bayad','tseke na bayad','cheke na bayad',
            'nag-issue ng check','nag-issue ng tseke','nagbigay ng check',
            'nagbigay ng tseke','nagbigay ng cheke','ibinigay na check'
        ]
    },
    {
        article: 'PD 1612', title: 'Fencing of Stolen Properties (Value ≤ P50)', victim: ['any','property'],
        keywords: [
            'fencing','fence','fences','fencing of stolen property',
            'fencing of stolen properties','fence stolen','fence stolen property',
            'stolen property','stolen properties','stolen goods','stolen item',
            'stolen items','stolen thing','stolen things','stolen stuff',
            'nakaw na gamit','nakaw na bagay','nakaw na mga gamit',
            'nakaw na mga bagay','nakaw na','nakaw','nagnakaw',
            'bumili ng nakaw','bumibili ng nakaw','bibili ng nakaw',
            'tumanggap ng nakaw','tumatanggap ng nakaw','tatanggap ng nakaw',
            'pinagbentahan ng nakaw','pinagbebentahan ng nakaw',
            'nagtago ng nakaw','nagtatago ng nakaw','itinago ang nakaw',
            'itinatago ang nakaw','itinago','itinatago',
            'kinupkop','kinukupkop','nagkupkop','nagkukupkop',
            'tinanggap','tinatanggap','tumanggap','tumatanggap',
            'binili','binibili','bumili','bumibili',
            'fence','fencer','fencing operation','stolen goods dealer',
            'dealer ng nakaw','tindero ng nakaw','nagtitinda ng nakaw',
            'nagbebenta ng nakaw','nagbebenta','nagtitinda'
        ]
    }
];

/* ============================================================
   SECTION B: STANDARD SUBJECTS + STANDARD-TO-LAW MAP
   ============================================================ */
window.STANDARD_SUBJECTS = [
    'Lending/Utang Issue','Property/Boundary Issue','Neighbor Dispute','Noise Complaint','Waste Management',
    'Animal Complaint','Road/Infrastructure','Physical Altercation','Threat/Intimidation','Verbal Abuse',
    'Scandal','Theft/Robbery','Cyber Libel','Child Protection Concern','Violence Against Women (VAWC)',
    'Illegal Gambling','Drug Related Issue','Illegal Vendors','Abandoned Vehicle','Obstruction on Public Way',
    'Illegal Structure','Water Supply Issue','Electrical Problem','Zoning Violation','Health Concern',
    'Calamity Assistance','Social Services Concern','Employment/Livelihood Issue','Education Assistance',
    'Medical Assistance','Death/Burial Assistance','Senior Citizen Concern','PWD Concern','Solo Parent Concern'
];

window.STANDARD_TO_LAW = {
    'Lending/Utang Issue': { article: 'BP 22', title: 'Issuing Checks Without Sufficient Funds' },
    'Property/Boundary Issue': { article: 'Art. 313', title: 'Altering Boundaries or Landmarks' },
    'Neighbor Dispute': { article: 'Art. 364', title: 'Intriguing Against Honor' },
    'Noise Complaint': { article: 'Art. 155', title: 'Alarms and Scandals' },
    'Waste Management': { article: 'Art. 329', title: 'Other Mischiefs (Value ≤ P1,000)' },
    'Animal Complaint': { article: 'Art. 329', title: 'Other Mischiefs (Value ≤ P1,000)' },
    'Road/Infrastructure': { article: 'Art. 329', title: 'Other Mischiefs (Value ≤ P1,000)' },
    'Physical Altercation': { article: 'Art. 266', title: 'Slight Physical Injuries and Maltreatment' },
    'Threat/Intimidation': { article: 'Art. 283', title: 'Light Threats' },
    'Verbal Abuse': { article: 'Art. 364', title: 'Intriguing Against Honor' },
    'Scandal': { article: 'Art. 155', title: 'Alarms and Scandals' },
    'Theft/Robbery': { article: 'Art. 309', title: 'Theft (Value ≤ P50)' },
    'Cyber Libel': { article: 'Art. 154', title: 'Unlawful Use of Means of Publication' },
    'Child Protection Concern': { article: 'Art. 276', title: 'Abandoning a Minor (Under 7 Years Old)' },
    'Violence Against Women (VAWC)': { article: 'Art. 266', title: 'Slight Physical Injuries and Maltreatment' },
    'Illegal Gambling': { article: 'Art. 155', title: 'Alarms and Scandals' },
    'Drug Related Issue': { article: null, title: null },
    'Illegal Vendors': { article: 'Art. 155', title: 'Alarms and Scandals' },
    'Abandoned Vehicle': { article: 'Art. 155', title: 'Alarms and Scandals' },
    'Obstruction on Public Way': { article: 'Art. 155', title: 'Alarms and Scandals' },
    'Illegal Structure': { article: 'Art. 312', title: 'Occupation of Real Property or Usurpation of Real Rights' },
    'Water Supply Issue': { article: 'Art. 329', title: 'Other Mischiefs' },
    'Electrical Problem': { article: 'Art. 329', title: 'Other Mischiefs' },
    'Zoning Violation': { article: 'Art. 312', title: 'Occupation of Real Property or Usurpation of Real Rights' },
    'Health Concern': { article: null, title: null },
    'Calamity Assistance': { article: null, title: null },
    'Social Services Concern': { article: null, title: null },
    'Employment/Livelihood Issue': { article: null, title: null },
    'Education Assistance': { article: null, title: null },
    'Medical Assistance': { article: null, title: null },
    'Death/Burial Assistance': { article: null, title: null },
    'Senior Citizen Concern': { article: null, title: null },
    'PWD Concern': { article: null, title: null },
    'Solo Parent Concern': { article: null, title: null }
};

/* ============================================================
   SECTION C: VICTIM CONTEXT PATTERNS
   ============================================================ */
window.VICTIM_PATTERNS = {
    minor: [
        'bata','batang','anak','anak ko','anak namin','anak nila','sanggol',
        'menor','menor de edad','minor','musmos','paslit','bagets',
        'estudyante','studyante','batang lalaki','batang babae','baby','newborn',
        'aking anak','aming anak','kanilang anak','anak ni','anak ng',
        'ubing','anak ku','anak mi','anak na','ubing ku','ubing mi',
        'batang wala pang','wala pang 7','wala pang pitong',
        'pitong taon','walong taon','siyam na taon','sampung taon'
    ],
    senior: [
        'matanda','matandang','lolo','lola','lolo ko','lola ko','lolo namin','lola namin',
        'senior','senior citizen','elderly','may edad','may edad na',
        '80 taong gulang','70 taong gulang','60 taong gulang','65 taong gulang',
        '80 anyos','70 anyos','60 anyos','65 anyos','75 anyos','85 anyos',
        'gurang','gurang na','matuang','matuang tao'
    ],
    woman: [
        'babae','asawa','asawa ko','asawa namin','asawa ni','maybahay','kabiyak',
        'kasintahan','girlfriend','partner','live-in','kinakasama','kerida',
        'nanay','ina','ina ko','ina namin','ina ni','mommy','mama','mother',
        'ate','ate ko','ate namin','babaeng kapatid','kapatid kong babae',
        'dalaga','dalagita','binibini','misis','ginang','ale',
        'babae kong anak','anak kong babae','anak na babae',
        'lola','lola ko','lola namin','lola ni',
        'babaeng senior','senior na babae'
    ],
    pwd: [
        'pwd','may kapansanan','disabled','disable',
        'wheelchair','naka-wheelchair','nakawheelchair','naka wheelchair',
        'bulag','bingi','pipi','pilay','putol ang paa','putol ang kamay',
        'amputee','may sakit','may sakit sa isip','may sakit sa utak',
        'autistic','autism','down syndrome','adhd','special child',
        'may depekto','kapansanan','kapansanan ko','kapansanan ni'
    ],
    property: [
        'lupa','lupang','bakod','bahay','sasakyan','kotse','motor','tricycle',
        'gamit','gamit ko','gamit namin','gamit ni','ari-arian','ari-arian ko',
        'ari-arian namin','ari-arian ni','property','kayarian','kayarian ku',
        'kayarian mi','lote','lupain','sakahan','bukid','taniman',
        'bakuran','pintuan','bintana','pader','bubong','kuryente',
        'tubig','poste','kable','wiring','pipa','tubo','gripo',
        'cellphone','laptop','computer','TV','radyo','ref',
        'bisikleta','bike','bag','purse','wallet','pitaka','pera','alahas',
        'relo','singsing','kwintas'
    ],
    public: [
        'publiko','kapitbahay','kalye','kalsada','daan','plaza','parke',
        'barangay','eskwelahan','simbahan','palengke','terminal',
        'kanto','kalsadang','lansangan','lugar','pampubliko'
    ]
};

/* ============================================================
   SECTION D: ESCALATION (NON-JURISDICTIONAL) PATTERNS
   ============================================================ */
window.ESCALATION_PATTERNS = {
    rape: ['ginahasa','gahasa','rape','raped','raping','pinilit na makipagtalik','pinilit makipagtalik','sexual assault','sexual abuse','pang-aabusong sekswal','panggagahasa','manggagahasa','nirape','pinagsamantalahan','pinasok ang ari','ipinasok ang ari','hindi pumayag pero pinilit','pinilit kahit ayaw','walang consent na pakikipagtalik'],
    vawc: ['ra 9262','vawc','violence against women','sinasaktan ako ng asawa','sinasaktan ako ng partner','binubugbog ako ng asawa','binubugbog ako ng partner','sinuntok ako ng asawa','sinuntok ako ng partner','sinakal ako ng asawa','sinakal ako ng partner','tinutukan ako ng asawa','tinutukan ako ng partner','pinagbantaan ako ng asawa','pinagbantaan ako ng partner','kinulong ako ng asawa','kinulong ako ng partner','sinisigawan ako ng asawa','sinisigawan ako ng partner','minumura ako ng asawa','minumura ako ng partner','psychological abuse','emotional abuse','economic abuse','pinagkakaitan ng pera ng asawa','pinagkakaitan ng pera ng partner'],
    child_abuse: ['ra 7610','child abuse','pang-aabuso sa bata','pang-aabuso sa menor','pang-aabuso sa menor de edad','sinasaktan ang bata nang malubha','binubugbog ang bata nang malubha','pinapalo ang bata ng matindi','pinapalo ang menor ng matindi','sinusunog ang bata','pinapaso ang bata','pinapaso ang kamay','pinapasok ang bata sa trabaho','pinagtatrabaho ang bata','child labor','batang nagtatrabaho','batang pinagtatrabaho','pinagbebenta ang bata','ibinibenta ang bata','nagbebenta ng bata','trafficking','human trafficking','child trafficking','pinagsasamantalahan ang bata','ginagalaw ang bata'],
    drugs: ['ra 9165','droga','drugs','shabu','marijuana','weed','nagbebenta ng droga','nagbebenta ng shabu','nagbebenta ng marijuana','nagtitinda ng droga','drug pusher','drug den','drug session','nagsa-shabu','nagdadrugs','gumagamit ng droga','adik','tulak','naglalako ng droga','may tulak sa barangay','drug laboratory'],
    illegal_gambling: ['pd 1602','illegal gambling','jueteng','tupada','sabong','cockfighting','e-sabong','online sabong','masiao','tong-its','pustahan sa kalye','nagsusugal','gambling','casino','pusoy','poker','pustahan','nagtaya','bet'],
    labor: ['labor dispute','labor case','dole','nlrc','hindi sumasahod','delayed sahod','mababa ang sahod','minimum wage','hindi binabayaran ang sahod','nawalan ng trabaho dahil','terminated','natanggal sa trabaho','illegal dismissal','constructive dismissal','unpaid wages','unpaid overtime','walang overtime pay','no overtime pay','walang sss','walang philhealth','walang pag-ibig','walang 13th month'],
    government: ['gobyerno','government','barangay official','kapitan','konsehal','mayor','gobernador','pulis','pulisya','government property','ari-arian ng gobyerno','public official','public officer','sangguniang barangay','sangguniang bayan'],
    no_private_party: ['public crime','krimen sa publiko','walang pribadong biktima','no private complainant','walang partikular na biktima'],
    high_penalty: ['rape','murder','homicide','kidnapping','kidnap for ransom','carnapping','robbery na may pananakit','robbery with violence','serious illegal detention','highway robbery','piracy','qualified piracy','terrorism','arson','pagtataksil sa bayan','treason','rebellion','sedition','coup d\'etat']
};

window.ESCALATION_LABELS = {
    rape: 'Sexual Assault / Rape',
    vawc: 'Violence Against Women and Children (RA 9262)',
    child_abuse: 'Child Abuse (RA 7610)',
    drugs: 'Illegal Drugs (RA 9165)',
    illegal_gambling: 'Illegal Gambling (PD 1602)',
    labor: 'Labor Dispute',
    government: 'Case Involving Government',
    no_private_party: 'Offense with No Private Party',
    high_penalty: 'Offense with Penalty Exceeding 1 Year'
};

window.ESCALATION_REFER_TO_SHORT = {
    rape: 'PNP-WCPD / DSWD',
    vawc: 'PNP Women\'s Desk / DSWD',
    child_abuse: 'PNP-WCPD / DSWD / Bantay Bata 163',
    drugs: 'PNP / PDEA / BADAC',
    illegal_gambling: 'PNP',
    labor: 'DOLE / NLRC',
    government: 'Proper Government Agency',
    no_private_party: 'PNP / Prosecutor\'s Office',
    high_penalty: 'PNP / Prosecutor\'s Office'
};

window.ESCALATION_SUBJECT = {
    rape: 'Child Protection Concern',
    vawc: 'Violence Against Women (VAWC)',
    child_abuse: 'Child Protection Concern',
    drugs: 'Drug Related Issue',
    illegal_gambling: 'Illegal Gambling',
    labor: 'Employment/Livelihood Issue',
    government: 'Other Concern',
    no_private_party: 'Other Concern',
    high_penalty: 'Other Concern'
};

window.ESCALATION_ACTIONS = {
    rape: { referTo: 'PNP Women & Children Protection Desk (WCPD) — tumawag sa 911', barangayDuty: ['Itatala ang insidente sa Barangay Blotter.','Mag-iisyu ng <strong>Referral Letter</strong> para sa PNP-WCPD at Prosecutor\'s Office.','Ipapaalam sa <strong>DSWD</strong> para sa kaligtasan ng biktima.','Kung bata ang biktima, ipapaalam din sa <strong>MSWDO</strong>.','Ipapaalam sa <strong>Municipal Health Office</strong> para sa medical exam kung kailangan.','Ipapaabot sa PNP ang <strong>preserved evidence</strong>.','HINDI maaaring i-mediate. HINDI maaaring i-areglo.'] },
    vawc: { referTo: 'PNP Women\'s Desk — tumawag sa 911', barangayDuty: ['Mag-iisyu ng <strong>Barangay Protection Order (BPO)</strong> — valid ng 15 araw, libre.','Itatala ang insidente sa Barangay Blotter.','Mag-iisyu ng <strong>Referral Letter</strong> para sa PNP-Women\'s Desk.','Ipapaalam sa <strong>DSWD / MSWDO</strong> para sa counseling at shelter.','Tutulungan ang biktima sa pag-file ng PNP report.','Kung may mga anak na kasama, ipapaalam sa <strong>Child Protection Unit</strong>.','HINDI maaaring i-mediate. HINDI maaaring i-areglo.'] },
    child_abuse: { referTo: 'PNP-WCPD at DSWD — tumawag sa 911 at sa Bantay Bata 163', barangayDuty: ['Ilalagay ang bata sa <strong>ligtas na lugar</strong>.','Mag-iisyu ng <strong>Referral Letter</strong> para sa PNP-WCPD at MSWDO.','Ipapaalam sa <strong>DSWD</strong> at <strong>Bantay Bata 163</strong> (1-800-1-888-163).','Ipapaalam sa <strong>Municipal Health Office</strong>.','Itatala ang insidente sa Barangay Blotter.','Kung nasa panganib pa, maaaring mag-isyu ng <strong>Barangay Protection Order</strong>.','HINDI maaaring i-mediate. HINDI maaaring ibalik ang bata sa suspek.'] },
    drugs: { referTo: 'PNP Anti-Illegal Drugs Group / PDEA — tumawag sa 911', barangayDuty: ['Ipapaalam agad sa <strong>PNP</strong> at <strong>PDEA</strong>.','Itatala ang insidente sa Barangay Blotter (walang pag-aresto).','Ipapaalam sa <strong>Barangay Anti-Drug Abuse Council (BADAC)</strong>.','HINDI maaaring i-mediate. HINDI maaaring i-areglo.','Ipapaalam sa <strong>MADAC</strong>.'] },
    illegal_gambling: { referTo: 'PNP — tumawag sa 911', barangayDuty: ['Ipapaalam agad sa <strong>PNP</strong>.','Itatala ang insidente sa Barangay Blotter.','HINDI maaaring i-mediate. HINDI maaaring i-areglo.'] },
    labor: { referTo: 'DOLE o NLRC', barangayDuty: ['Mag-iisyu ng <strong>Referral Letter</strong> para sa DOLE o NLRC.','Ipapaalam sa <strong>PESO</strong> kung may livelihood concern.','HINDI maaaring i-mediate. HINDI maaaring i-areglo.'] },
    government: { referTo: 'Kaukulang ahensya ng gobyerno o korte', barangayDuty: ['Itatala ang insidente sa Barangay Blotter.','Ipapaalam sa kaukulang ahensya.','HINDI maaaring i-mediate. HINDI maaaring i-areglo.'] },
    no_private_party: { referTo: 'PNP o Prosecutor\'s Office', barangayDuty: ['Itatala ang insidente sa Barangay Blotter.','Ipapaalam sa PNP o Prosecutor\'s Office.','HINDI maaaring i-mediate dahil walang pribadong partido.'] },
    high_penalty: { referTo: 'PNP at Prosecutor\'s Office', barangayDuty: ['Ipapaalam agad sa <strong>PNP</strong> at <strong>Prosecutor\'s Office</strong>.','Itatala ang insidente sa Barangay Blotter.','HINDI maaaring i-mediate dahil ang parusa ay lumalampas sa 1 taon o ang multa ay higit sa P5,000.'] }
};

window.ANONYMOUS_ELIGIBLE_ESCALATIONS = ['drugs','illegal_gambling','government','rape','vawc','child_abuse','high_penalty'];

/* ============================================================
   SECTION E: VALIDATION PLACEHOLDERS + JUNK TOKENS
   ============================================================ */
window.VALIDATION_PLACEHOLDERS = [
    'test','testing','tester','sample','n/a','na','none','null','nil',
    'anonymous','anon','asdf','asdfasdf','asdfgh','qwerty','qwertyuiop',
    'zxcvbn','zxcvbnm','hjkl','xxx','xxxx','aaaa','bbbb','cccc',
    'abc','abcd','abcde','abcdef','123','1234','12345','name','fullname',
    'firstname','lastname','first name','last name','no name','noname',
    'unknown','noone','nobody','john doe','jane doe','juan dela cruz',
    'juandelacruz','juandc','juan d','juan','pedro','pedro penduko',
    'lorem ipsum','lorem','ipsum','placeholder','tbd','tba','todo',
    'asdfasdfasdf','asdasd','asdasdasd','hahaha','hahahaha','hehehe',
    'jejeje','lol','lmao','wtf','test test','test name','name here'
];

window.VALIDATION_JUNK_TOKENS = [
    'test','testing','sample','asdf','asdfasdf','asdfgh','qwerty','zxcvbn',
    'hjkl','123','1234','12345','aaaa','bbbb','cccc','dddd','abc','abcd',
    'abcde','lorem','ipsum','none','n/a','na','null','xxx','yyy','zzz'
];

window.KEYBOARD_MASHES = [
    'asdf','asdfg','asdfgh','asdfghj','qwerty','qwertyu','qwertyui',
    'zxcvb','zxcvbn','hjkl','fghj','dfgh','sdfg','sdfgh','sdfghj',
    'hjklm','xcvbn','xcvbnm','ghjkl','bnm,','ghjk'
];

/* ============================================================
   SECTION G: POINTING-SYSTEM WEIGHTS
   ============================================================
   Tuned so the dropdown shows ALL plausible laws — even those
   that matched just one keyword. Ranking does the work; we don't
   filter aggressively.
   ============================================================ */
window.KEYWORD_WEIGHTS = {
    // Points per keyword occurrence, weighted by keyword length
    single:          1,     // 1-word keyword
    phrase2:         3,     // 2-word keyword
    phrase3plus:     5,     // 3+ word keyword
    maxOccurrences:  3,     // cap repeats so one long word can't dominate
    victimBonus:     4,     // multiplier when victim-context matches

    // Dropdown population — be permissive
    minScoreToShow:  1,     // ← was 2; NOW 1 so ANY keyword match shows up
    maxCandidates:   15,    // ← was 8; NOW 15 so more laws appear
    topCandidateBadge: 'Best Match',
};

console.log('⚖️  KEYWORD_WEIGHTS loaded:', JSON.stringify(window.KEYWORD_WEIGHTS));

console.log('✅ complaint_keywords.js loaded — all classifier keyword data available on window.');