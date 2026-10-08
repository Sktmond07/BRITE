// resident_complaint.js
// Barangay San Bartolome Katarungang Pambarangay Classifier
// Role-aware complaint view + card view + filter chips + documentable UI + live hearing timer

let currentComplaintId = null;
let complainantsList = [];
let respondentsList = [];
let witnessesList = [];
let incidentsList = [];

let suppressSubjectChangeEvent = false;
let userInteractedWithSubject = false;

// Role-aware tabs
let currentComplaintTab = 'complainant';
let currentStatusFilter = 'all';  // 'all' | specific status
let cachedComplaints = { as_complainant: [], as_respondent: [] };

let __liveTimerInterval = null;

// ============================================================
// SECTION 1: THE 45 LAWS
// ============================================================
const barangayLaws = [
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
            'pahayag','nagbigay pahayag','nagbigay ng pahayag',
            'salita','sinalita','sasalita','mesalita','pamisalita',
            'masamang amanu','post','magpost','megpost',
            'comment','magcomment','megcomment',
            'libel','maglibel','meglibel','panlibel',
            'facebook','fb','social media','twitter','tiktok','instagram',
            'cyber','online','udyok','inudyok','mangudyok','pangudyok',
            'hikayat','hinikayat','manghikayat','panghikayat',
            'amanu','binie amanu','mamie amanu','pamie amanu',
            'sabi','sinabi','sasabian','mesabi','pamisabi'
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
            'aabot ng gabi','hanggang gabi','hanggang madaling araw',
            'maingay ang videoke','maingay ang videoke ng kapitbahay',
            'maingay ang kapitbahay','maingay na videoke','maingay na karaoke',
            'maingay na musika','nagpapatugtog ng malakas',
            'nagvivideoke ng malakas','nagvivideoke hanggang gabi',
            'nagpapatugtog hanggang gabi','ingay ng videoke',
            'ingay ng kapitbahay','ingay ng motor','ingay ng construction',
            'ingay ng nagpapatayo',
            'makain','makain ya','mingay','mingay ya','pamakin',
            'gulu','magulu','magulu ya','gemulu','kaguluan',
            'sigaw','sinigaw','sisigaw','mesigaw','sigawan','pamisigaw',
            'kagulwan','mikagulu','iskandalu','miskandalu','mangiskandalu',
            'videoke','mamagvideoke','megvideoke','karaoke','mamagkaraoke',
            'megkaraoke','musika','malauting musika','malauting tunug',
            'mamagpatigtug','megpatigtug','patigtug','tigtug',
            'kasiyahan','mangasiyahan','miskasiyahan','handaan','minandaan',
            'manghandaan','pamangan','kaluguran','makaluguran',
            'sirena','tumigtig','abu-abu','buung bengi','bengi-bengi',
            'tune king bengi','tune king abak'
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
            'peke ang lagda','pekeng lagda','peke ang pirma','pekeng pirma',
            'peka','peka ya','peka-peka','mamagpeka','magpeka','megpeka',
            'peka certificate','peka sertipiko','peka dokumento','peka papel',
            'peka ID','peka lisensya','peka pemit',
            'gawa-gawa','ginawa','megawa','falsu','mamalsu','magpalsu',
            'falsipikado','falsipikasyon','falsipikasyun',
            'lagda','peka lagda','firmado','falsong firmado',
            'firma','peka firma','peka pirma','selyo','peka selyo'
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
            'nagpanggap na barangay','nagpanggap na tanod','nagpanggap na guro',
            'lagyu','lagyu na','lagyu ya','peka lagyu','alang lagyu',
            'mamaglagyu','maglagyu','meglagyu',
            'katawan','peka katawan','pagkatao','peka pagkatao',
            'panggap','nagpanggap','mamanggap','magpanggap','megpanggap',
            'mitago','magtago','megtago','mangtago',
            'tagu','tinagu','tatagu','tetaguan','metagu',
            'kilala','kinilala','kikilalanin','mekilala','pakikilala',
            'magpakilala','megpakilala','pakilala','nagpakilala',
            'peka papel','peka dokumento','peka ID',
            'alias','may alias','gamit alias','gumamit alias'
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
            'gumawa ng pekeng uniporme','gumawa ng pekeng badge',
            'gumawa-gawang uniporme','gawa-gawang badge',
            'nagsuot ng uniporme','nagsusuot ng uniporme','nagsuot ng pekeng',
            'nagsuot ng pekeng uniporme','nagsuot ng pekeng badge',
            'uniporme','unipormi','uniporme ya','unipormi ya',
            'peka uniporme','peka unipormi','peka badge','peka insignia',
            'panggap','panggap a tanod','panggap a pulis','panggap a opisyal',
            'panggap a kapitan','panggap a konsehal','panggap a mayor',
            'tanod','tanod ya','pulis','pulis ya','opisyal','opisyal ya',
            'kapitan','kapitan ya','konsehal','konsehal ya','mayor','mayor ya',
            'suut','sinuut','susulud','mesuut','magsuut','suut ya',
            'gamit','ginamit','gagamitan','megamit','gumamit',
            'badge','badge ya','insignia','insignia ya','sinturon'
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
            'nadamay ng','nadadamay ng','nasangkot','nasasangkot','nasangkot sa',
            'gulu','magulu','magulu ya','gemulu','kaguluan','mikagulu',
            'labuad','milabuad','pamilabuad',
            'away','inaway','mangaway','menaway','penaway',
            'sabung','sinabung','sasabung','mesabung','pamisabung',
            'bakal','binakal','babakalan','mebakal','pamakal',
            'sabong','sinabong','sasabong','mesabong',
            'tudtud','tinudtud','tutudtud','metudtud',
            'laban','linaban','lalaban','melaban','pamlaban',
            'sugat','sinugat','susugat','mesugat','pamisugat',
            'damay','dinamay','dadamay','medamay','pamdamay',
            'sangkut','sinangkut','sasangkut','mesangkut','pamsangkut'
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
            'nagbigay ng tulong','nagbigay ng tulong sa pagpapakamatay',
            'tumulong','tumutulong','tumulong sa','tumutulong sa',
            'pakamate','magpakamate','mamamate','menamate','pamate',
            'tulung','tinulung','tutulung','metulung','pamtulung',
            'saklulu','sinaklulu','sasaklulu','mesaklulu','pamsaklulu',
            'udyok','inudyok','mangudyok','pangudyok',
            'hikayat','hinikayat','manghikayat','panghikayat',
            'boluntad','sarili','pamate king sarili','pakamate king sarili',
            'tulung a mate','tulung a pakamate','tulung pakamate'
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
            'hamunan','hamunan sa','hamunan ng','naghamunan',
            'lalabanan sa','naglabanan','naglalabanan',
            'duwelu','mangduwelu','menuwelu','panduwelu',
            'hamun','hinamun','hahamunin','mehamun','pamhamun',
            'salungat','sinlungat','sasalungat','mesalungat','pamsalungat',
            'tuki','tinuki','tutuki','metuki','pagtuki',
            'sugal','sinugal','sasugal','mesugal','pamisugal',
            'takda','tinakda','tatakda','metakda','pamtakda'
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
            'patingin sa ospital',
            '1-30 days','1 to 30 days','isang linggo','isang buwan',
            'sugat','sinugat','susugat','mesugat','pamisugat',
            'sugat a maragul','sugat a malaut','sugat a masakit',
            'pasa','pinasa','papasa','mepasa','pampasa',
            'lusu','linusu','lulusu','melusu','pamlusu',
            'dugu','dinugu','dudugu','medugu','pamdugu',
            'bali','biniali','babali','mebali','pambali',
            'ospital','mangospital','menospital','pangospital'
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
            'sinasaktan ang bata','sinasaktan ang menor','sinasaktan ang anak',
            '1-9 days','1 to 9 days','isang araw','dalawang araw','tatlong araw',
            'suntuk','sinuntuk','susuntuk','mesuntuk','pamsuntuk',
            'sipa','sinipa','sisipa','mesipa','pamsipa',
            'tampaling','tinampaling','tatampaling','metampaling',
            'palu','pinalu','papalu','mepalu','pampalu',
            'tulak','tinulak','tutulak','metulak','pamtulak',
            'sabunot','sinabunot','sasabunot','mesabunot','pamsabunot',
            'kalmut','kinalmut','kakalmut','mekalmut','pamkalmut',
            'kagat','kinagat','kakagat','mekagat','pamkagat',
            'malupit','minamalupit','mamalupit','memalupit','pamalupit',
            'sakit','sinakit','sasakit','mesakit','pamsakit'
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
            'kinulong nang walang kaso','kinulong nang walang warrant',
            'huli','hinuli','huhuli','mehuli','pamhuli',
            'kulung','kinulung','kukulung','mekulung','pamkulung',
            'presu','mekulung','meprisu','pangaprisu',
            'preso','mepreso','pangapreso',
            'karsel','kinarsel','kakarsel','mekarsel','pamkarsel',
            'alang kasu','alang warrant','alang basehan','alang dahilan',
            'alang kaso','alang sabi','alang abiso','alang balita',
            'bitbit','binibitan','bibitin','mebitbit','pambitbit'
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
            'hinikayat maglayas','hinikayat tumakas','hinikayat umalis','hinikayat lumayas',
            'udyok','inudyok','mangudyok','pangudyok',
            'anak','inank','aanak','meanak','pamanak',
            'ubing','ubing ya','inubing','uubing','meubing','pamubing',
            'bata','bata ya','binata','babata','mebata','pambata',
            'takyaw','tinakyaw','tatakyaw','metakyaw','pamtakyaw',
            'laku','linaku','lalaku','melaku','pamlaku',
            'tali','tinali','tatali','metali','pamtali'
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
            'walang pakialam sa kapwa','walang malasakit','walang malasakit sa',
            'iwang','iniwang','iiwang','meiwang','pamiwang',
            'tali','tinali','tatali','metali','pamtali',
            'tetalakdan','telakdan','talakdan',
            'pabaya','pinabaya','papabaya','mepabaya','pampabaya',
            'malasakit','alang malasakit','pakialam','alang pakialam',
            'tulung','tinulung','tutulung','metulung','pamtulung',
            'saklulu','sinaklulu','sasaklulu','mesaklulu','pamsaklulu',
            'salbak','sinalbak','sasalback','mesalbak','pamsalbak'
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
            'iniwan sa kotse','iniwan sa tricycle','iniwan sa jeep',
            'iwang','iniwang','iiwang','meiwang','pamiwang',
            'anak','inank','aanak','meanak','pamanak',
            'ubing','ubing ya','inubing','uubing','meubing','pamubing',
            'bata','bata ya','binata','babata','mebata','pambata',
            'pitu','pitung','pitung taon','pitu ya taon',
            'alang pitu','alang pitung','kulang pitu','kulang pitung',
            'pabaya','pinabaya','papabaya','mepabaya','pampabaya',
            'abandonu','inabandonu','abandonu ya','mangabandonu'
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
            'walang pakialam','walang pakialam sa anak','walang pakialam sa bata',
            'iwang','iniwang','iiwang','meiwang','pamiwang',
            'indung','indung ya','indung ku','indung mi','indung na',
            'tata','tata ya','tata ku','tata mi','tata na',
            'pabaya','pinabaya','papabaya','mepabaya','pampabaya',
            'anak','inank','aanak','meanak','pamanak',
            'ubing','ubing ya','inubing','uubing','meubing','pamubing',
            'kustudiya','kustudiya ya','mangustudiya','pangustudiya',
            'responsibilidad','responsibilidad ya','may responsibilidad',
            'tungkulin','tungkulin ya','mangatungkulin','pangatungkulin',
            'malasakit','alang malasakit','pakialam','alang pakialam',
            'abandonu','inabandonu','abandonu ya','mangabandonu'
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
            'pilit pumasok','pinilit pumasok',
            'suklab','sinuklab','sasuklab','mesuklab','pamsuklab',
            'loob','linub','lulub','melub','pamlub',
            'sablay','sinablay','sasablay','mesablay','pamsablay',
            'alang paintulut','alang pemit','alang paalam','alang abiso',
            'alang sabi','e ne sasabian','e na paintulut','e na pemit',
            'alang gulu','alang takot','alang sindak',
            'balay','balay ya','binelay','babelay','mebalay','pambalay'
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
            'trespassing','trespasser','trespassers','nagtrespass',
            'suklab','sinuklab','sasuklab','mesuklab','pamsuklab',
            'loob','linub','lulub','melub','pamlub',
            'sablay','sinablay','sasablay','mesablay','pamsablay',
            'alang paintulut','alang pemit','alang paalam','alang abiso',
            'alang sabi','e ne sasabian','e na paintulut','e na pemit',
            'labuad','labuad ya','linabuad','lalabuad','melabuad','pamlabuad'
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
            'pangamba sa buhay','pangamba sa kaligtasan','pangamba sa pamilya',
            'banta','binanta','babanta','mamanta','pamanta',
            'takot','tinakot','tatakut','tetakutan','metakot',
            'pangatakot','pangatatakot','katakutan',
            'sindak','sinindak','sasindak','mesindak','pamsindak',
            'bantang','bantang ya','binantang','babantang','mebantang',
            'pangatakut','pangatatakut','pangatakut ya','kapangatakut'
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
            'banta sa pamamagitan ng','banta gamit ang',
            'banta king sulat','banta king text','banta king chat',
            'banta king messenger','banta king social media','banta king fb',
            'menanta','menanta ya','mananta','mananta ya','miminta','miminta ya'
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
            'sapilitang pagbibigay','sapilitang pagkuha','sapilitang paggawa',
            'pilitin','pipilitin','pinipilit','pinilit',
            'sapilitan','sinapilitan','sasapilitan','mesapilitan',
            'tud','tinud','tutud','tetudan','metud',
            'tutuk','tinutuk','tututuk','tetutukan','metutuk',
            'mawang','minawang','mamawang','memawang','pamawang',
            'takot','tinakot','tatakut','tetakutan','metakot'
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
            'pipilitin sumama','pinipilit sumama','pinilit sumama',
            'pilit','pinilit','pipilit','mepilit','pamipilit',
            'pilitan','pinilitan','pipilitan','mepilitan',
            'pagawa','pinagawa','pagagawain','megawa','pamagawa',
            'sunud','sinunud','susunud','mesunud','pamsunud'
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
            'compulsory merchandise','compulsory wage','payment by tokens',
            'pilit','pinilit','pipilit','mepilit','pamipilit',
            'sali','sinali','sasali','mesali','pamisali',
            'bakal','binakal','babakalan','mebakal','pamakal',
            'bayad','binayad','babayaran','mebayad','pambayad',
            'sueldu','sueldu ya','sueldu ku','sueldu na',
            'supisyenti','supisyenti ya','supisyenti ku','supisyenti na'
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
            'sapilitang pagbuo','sapilitang pagbuo ng',
            'unyun','unyun ya','mangunyu','mangunyun','pangunyu',
            'sali','sinali','sasali','mesali','pamisali',
            'pilit','pinilit','pipilit','mepilit','pamipilit',
            'kapisanan','kapisanan ya','mikapisanan','pangapisanan',
            'samahan','samahan ya','misamahan','pamsamahan',
            'grupu','grupu ya','migrupu','pamigrupu',
            'trabahu','trabahu ya','mitrabahu','pamitrabahu',
            'takot','tinakot','tatakut','metakot','pamtakot'
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
            'nagbukas ng liham','nagbukas ng sulat','nagbukas ng email',
            'sulat','sulat ya','sinulat','susulat','mesulat','pamsulat',
            'bukas','binukas','bubukas','mebukas','pambukas',
            'lihim','lihim ya','linihim','lilihim','melihim','pamlihim',
            'liham','liham ya','liniham','liliham','meliham','pamliham',
            'sobre','sobre ya','sinobre','susobre','mesobre','pamsobre',
            'email','email ya','email ku','email mi','email na',
            'kuwa','kinuwa','kukuwa','mekuwa','pangkuwa',
            'basa','binasa','babasa','mebasa','pambasa'
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
            'nagsumbong','nagsusumbong','nagsumbong ng',
            'abusu','inabusu','abusu ya','mangabusu','mangabuso',
            'abuso','inabuso','mangabuso','pangabuso',
            'kapangyarihan','kapangyarihan ya','mangapangyarihan',
            'puwesto','puwesto ya','mangapuwesto','pangapuwesto',
            'tungkulin','tungkulin ya','mangatungkulin','pangatungkulin',
            'sikretu','sikretu ya','sinikretu','sisikretu','mesikretu',
            'sikreto','sikreto ya','sinikreto','sisikreto','mesikreto',
            'sumbung','sinumbung','susumbung','mesumbung','pamsumbung',
            'siwalat','siniwalat','sisisiwalat','mesiwalat','pamsiwalat'
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
            'nakaw','minakaw','manakaw','maninakaw','makaw','kinaw',
            'kinaw ne','kinakaw','kakanawan','mekaw',
            'kuwa','kinuwa','kukuwa','mekuwa','pangkuwa',
            'takyaw','tinakyaw','tatakyaw','metakyaw','pamtakyaw',
            'sibsit','sinibsit','sasibsit','mesibsit','pamsibsit',
            'alang 50','dili','dinili','duduli','medili'
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
            'below 500','less than 500','500 piso','500 pesos','limandaang piso',
            'nakaw','minakaw','manakaw','maninakaw','makaw','kinaw',
            'suklab','sinuklab','sasuklab','mesuklab','pamsuklab',
            'loob','linub','lulub','melub','pamlub',
            'akyat','inakyat','aakyat','meakyat','pangakyat',
            'alang 500','500 piso','500 pesos'
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
            'nasakop ang lupa namin','nakuha ang bahagi ng lupa namin',
            'sakup','sinakup','sasakup','mesakup','pamsakup',
            'kuwa','kinuwa','kukuwa','mekuwa','pangkuwa',
            'lupa','lupa ya','linupa','lulupa','melupa','pamlupa',
            'tuknang','tinuknang','tutuknang','metuknang','pamtuknang',
            'upahan','inupahan','uupahan','meupahan','pangupahan',
            'tira','tiniran','tutiran','metiran','pamtira'
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
            'landmark','landmarks','bakod','fence','fences',
            'bakud','bakud ya','binakud','babakuran','mebakud','pambakud',
            'dulung','dulung ya','dinulung','dudulung','medulung','pamdulung',
            'lindug','lindug ya','linindug','lilindug','melindug','pamlindug',
            'tanda','tanda ya','tinanda','tutanda','metanda','pamtanda',
            'hangganan','hangganan ya','hinangganan','hihingganan','mehangganan',
            'mojon','mojon ya','minojon','mumojon','memojon','pamojon',
            'alkus','alkus ya','inalkus','aalukusan','mealkus','pamalkus'
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
            'below 200','less than 200','200 piso','200 pesos','dalawandaang piso',
            'daya','dinaya','dinadaya','mandaya','mandaraya','pandaya',
            'luku','linuku','luluku','meluku','pamluku',
            'luku-luku','linuku-luku','luluku-luku','meluku-luku',
            'daya king kwarta','dinaya king kwarta','dinaya keng kwarta',
            'kuwa','kinuwa','kukuwa','mekuwa','pangkuwa',
            'kwarta','kwarta ya','kinwarta','kukwarta','mekwarta','pamkwarta',
            'alang 200','estapa','inestapa','mangestapa','pangestapa'
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
            'panlilinlang','nanglilinlang',
            'daya','dinaya','dinadaya','mandaya','mandaraya','pandaya',
            'luku','linuku','luluku','meluku','pamluku',
            'benta','binenta','bebenta','mebenta','pambenta',
            'benta king ali tutu','binenta king ali tutu',
            'daya king','dinaya king','dinaya keng',
            'kuwa','kinuwa','kukuwa','mekuwa','pangkuwa',
            'sambut','sinambut','sasambut','mesambut','pamsambut'
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
            'bata na dinaya','batang dinaya','menor na dinaya',
            'daya','dinaya','dinadaya','mandaya','mandaraya','pandaya',
            'anak','anak ya','inank','aanak','meanak','pamanak',
            'ubing','ubing ya','inubing','uubing','meubing','pamubing',
            'bata','bata ya','binata','babata','mebata','pambata',
            'luku','linuku','luluku','meluku','pamluku',
            'luku king anak','linuku king anak','linuku keng anak',
            'luku king ubing','linuku king ubing','linuku keng ubing'
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
            'naglinlang','nilinlang','nanglilinlang',
            'daya','dinaya','dinadaya','mandaya','mandaraya','pandaya',
            'luku','linuku','luluku','meluku','pamluku',
            'luku-luku','linuku-luku','luluku-luku','meluku-luku',
            'sambut','sinambut','sasambut','mesambut','pamsambut',
            'taksya','tinaksya','tattaksya','metaksya','pamtaksya',
            'kalokuan','kinilokuan','kakilokuan','mekilokuan'
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
            'property','ari-arian','pag-aari','lupa','bahay','sasakyan',
            'walang pahintulot','walang paalam','hindi pinayagan',
            'inalis ang','kinuha ang','ibinenta ang','isinangla ang',
            'alisi','inalisi','mag-alis','ag-alis','mangalis','menalisi',
            'benta','ibenta','binenta','mamiali','memiali','pamibenta',
            'sangla','sinangla','makasangla','magsangla','pamisali','kasalang',
            'ipisali','penali','sinali','seli','sisali','sanglaan',
            'mikasalang','mitakda','pamagbenta','pamamisali',
            'alang paintulut','alang pemit','alang paalam','alang abiso',
            'kayarian','kayarian na','kayarian ku','kayarian mi',
            'tierra','lote','lupang','balen','pamana','herencia'
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
            'punit','pinunit','pinupunit','napunit','napupunit',
            'sinira','sisira','misira','misisira','peras','peras ne','peras na',
            'panyira','manyira','maninira','pamisali',
            'basag','binasag','babasagin','memasag','mabasag','mebasag',
            'putut','pinutut','pupututin','meputut','pamutut',
            'sindi','sinindi','sisindian','mesindi',
            'sabug','sinabug','sasabugan','mesabug',
            'alang paintulut','alang pemit','alang paalam',
            'destrosu','destrusu','destruksion','destruksyun',
            'kayarian','kayarian na','kayarian ku','kayarian mi',
            'below 1000','less than 1000','1000 piso','1k','1000 pesos'
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
            'below 1000','less than 1000','1000 piso','1k','1000 pesos',
            'basura','magbasura','memasura','mamagbasura','mangabul','manabul',
            'binabudbud','babudburan','mebudbud','budbud','budbudan',
            'tabug','tinabug','tatabugan','metabug',
            'sabuy','sinabuy','sasabuyan','mesabuy',
            'putik','maputik','meputik','pamuputik','maniputik',
            'malangi','malangsi','malangse','mabahu','mabahu ya','kabahu',
            'sanglu','masanglu','mesanglu','sasanglu','kasangluan',
            'alang linis','maruk','maruk ya','karuk','karukan',
            'rusing','rusingan','marusing','marusing ya','karusing',
            'tapon','tetapon','metapon','itapon',
            'basural','basuralan','kasuelo','kadumi','karumi',
            'inmundisia','imundisia','peste','mapeste','mameste'
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
            'hindi kasal','walang kasal','hindi pinakasalan','hindi pinapanagot',
            'tinud','tutud','manud','tetud','metud','metud ya',
            'inakit','mangakit','pangakit','pangakit na',
            'menako','manako','memanako','pammanako',
            'besa','binensa','babensaan','mebensa','mebensa ya',
            'buntis','binuntis','mabuntis','mebuntis',
            'iniwan','iniwan ne','tetalakdan','telakdan','talakdan',
            'alang asawa','alang kasal','e kasal','e re pinakasal',
            'e ne panagutan','e ne aako','e ne abalu','e ne pakibalu',
            'daya','dinaya','mangdaya','mandaraya',
            'amante','kabit','kerida','querida',
            'abandonadu','abandonada','embarasada','preñada','preñada ya'
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
            'nambabastos','pambabastos','pangbabastos',
            'malaswa','malaswa ya','malaswang','kalaswaan','kalaswan',
            'gemal','gagal','gemalan','gagalan','ginalaw','ginalaw ne',
            'galaw','ginagalaw','gagalaw','galawan','galawanan',
            'kapkap','kinapkap','kakapkapan','mekapkap',
            'pisil','pinisil','pipisil','mepisil',
            'dila','dinilaan','didilaan','medilaan',
            'sipsip','sinipsip','sisipsip','mesipsip',
            'bastus ya','binastus','babastusan','mebastus',
            'insultu','ininsultu','mang-insultu',
            'abusu','inabusu','mangabusu','mangabuso'
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
            'banta ng','bantang','bantang ipapahiya','bantang ibubunyag',
            'banta','binanta','babanta','mamanta','pamanta',
            'takot','tinakot','tatakut','tetakutan','metakot',
            'pangatakot','pangatatakot','katakutan',
            'sumbung','sinumbung','susumbung','mesumbung',
            'isumbung','isusumbung','ipasumbung','pamisumbung',
            'bunyag','binunyag','bubunyag','mebunyag','pamunyag',
            'pahiya','pinahiya','papahiya','mepahiya',
            'kahiya','kahiya ya','kahihiyan','makakahiya','nakakahiya',
            'salita','sinalita','sasalita','mesalita','pamisalita',
            'amenasa','inamenasa','amenasahan','maamenasa',
            'amenaza','pangaamenasa','intimidasion','intimidasyun',
            'intimida','inintimida','amenaza de publicar'
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
            'opisyal','official','awtoridad','authority','otorsidad',
            'sikretu','sikretu ya','sikretu na','misikretu',
            'sikreto','sikreto ya','sikreto na','misikreto',
            'pemagbawal','pipagbawal','agbawal','magbawal','bawalan',
            'pipigilan','pigilan','mipigil','mipigil ya',
            'tinago','tinago ne','tatago','tetaguan','metago',
            'tagu','tinagu','tatagu','tetaguan','metagu',
            'ilihim','ininilihim','ililihim','melihim',
            'lihim','lilihim','linihim','lilihimen','melilihim',
            'mag-ulat','meg-ulat','ipag-ulat','ipag-ulat ne',
            'magsalita','megsalita','ipagsalita','ipagsalita ne',
            'sekreto','sekreto ya','misekreto','sikretu',
            'prohibidu','prohibida','prohibido','prohibision','prohibisyun',
            'kasu','kasu ya','kasung','kasung opisyal','kasung barangay'
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
            'ibinintang','ibinabintang','ibintang','ibinintang sa',
            'bintang','binintang','bibintang','mamintang',
            'pamintang','pamintang na','pamintang ne','kapamintang',
            'sumbung','sinumbung','susumbung','mesumbung',
            'paratang','pinaratang','paparatang','meparatang',
            'kasalanan','kinasalanan','kakasalanan','mekasalanan',
            'kalokuan','kinilokuan','kakilokuan','mekilokuan',
            'sinungaling','sinungaling ya','magsinungaling','megsinungaling',
            'salita','sinalita','sasalita','mesalita','pamisalita',
            'peksa','pemaksa','papaksa','mepaksa','pamaksa',
            'imbentu','inimbentu','iimbentuan','meimbentu','pamimbentu',
            'gawa-gawa','gawa-gawa ne','gawa-gawa ya','megawa-gawa',
            'tud','tinud','tutud','tetudan','metud','metud ya',
            'turu','tinuru','tuturu','teturuan','meturu','meturu ya',
            'tudtud','tinudtud','tutudtud','tetudtud','metudtud',
            'kabalastugan','kinabalastugan','kakabalastugan','mekabalastugan',
            'akusasion','akusasyun','akusa','inakusa','akusahan','akusadu',
            'akusada','pangaakusa','testigu','testigus',
            'falsong testigu','testimonio','falsong testimonio'
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
            'pananakit ng salita','pananakit sa pamamagitan ng salita',
            'tsismis','tsismis ya','magtsismis','megtsismis','tsismoso',
            'tsismosa','tsismoso ya','tsismosa ya',
            'intriga','inintriga','iintriga','meintriga','pang-intriga',
            'siraan','siniraan','sisiraan','mesiraan',
            'purik','pinurik','purikan','mepurik','mepurik ya',
            'tukso','tinutukso','tutukso','tetukso','metukso',
            'lait','nilait','lalaitin','lelait','melait','paglait',
            'mura','minura','mumura','memura','pamura',
            'bastus','binastus','babastusan','mebastus','pambastus',
            'insultu','ininsultu','insultu ya','mang-insultu',
            'panlalait','manlalait','panlalait na','manlalait ya',
            'sinala','sinasala','sasalaan','mesala','pamisala',
            'karangalan','karangalan ya','dangal','dangal na',
            'kalait','kinalait','kakalait','mekalait'
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
            'nagbigay ng tseke','nagbigay ng cheke','ibinigay na check',
            'tseke','cheke','tseke ya','cheke ya','magtseke','magcheke',
            'ibie tseke','ibie cheke','binie tseke','binie cheke',
            'alang pondo','alang lamang','alang lawe','alang kwarta',
            'tumalbog','tinmalbug','tatalbug','metalbog','metalbog ya',
            'patalbug','talbugan','talbog','telbug',
            'balik','binie balik','ibabalik','babalik','mibalik','mibalik ya',
            'bangku','banku','bangku ya','tseke king bangku',
            'cheke king bangku','checking account','kuwenta',
            'sensilyu','kwarta','kwarta na','alang kwarta','walang kwarta'
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
            'nagbebenta ng nakaw','nagbebenta','nagtitinda',
            'kinuha','kinukuha','kumuha','kumukuha',
            'below 50','less than 50','50 piso','50 pesos',
            'nakaw','minakaw','manakaw','maninakaw','makaw','kinaw',
            'kinaw ne','kinakaw','kakanawan','mekaw','mekaw ya',
            'binili','binili ne','bibili','mibili','mamili','mimili',
            'tanggap','tinanggap','tatanggap','metanggap','manganggap',
            'sali','sinali','sasali','mesali','pamisali',
            'tagu','tinagu','tatagu','tetaguan','metagu',
            'kupkop','kinupkop','kukupkop','mekupkop','pangupkop',
            'tindera','tindero','manindera','manindero','tindahan',
            'bakal','binakal','babakalan','mebakal','mamakal',
            'kuwa','kinuwa','kukuwa','mekuwa','mangkuwa',
            'alang 50','50 piso','50 pesos','limampung piso'
        ]
    }
];

// ============================================================
// SECTION 2: STANDARD SUBJECTS
// ============================================================
const standardSubjects = [
    'Lending/Utang Issue','Property/Boundary Issue','Neighbor Dispute','Noise Complaint','Waste Management',
    'Animal Complaint','Road/Infrastructure','Physical Altercation','Threat/Intimidation','Verbal Abuse',
    'Scandal','Theft/Robbery','Cyber Libel','Child Protection Concern','Violence Against Women (VAWC)',
    'Illegal Gambling','Drug Related Issue','Illegal Vendors','Abandoned Vehicle','Obstruction on Public Way',
    'Illegal Structure','Water Supply Issue','Electrical Problem','Zoning Violation','Health Concern',
    'Calamity Assistance','Social Services Concern','Employment/Livelihood Issue','Education Assistance',
    'Medical Assistance','Death/Burial Assistance','Senior Citizen Concern','PWD Concern','Solo Parent Concern'
];

const standardToLaw = {
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

// ============================================================
// SECTION 3: VICTIM CONTEXT DETECTION
// ============================================================
const VICTIM_PATTERNS = {
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

function detectVictimContext(description) {
    const ctx = { minor: 0, senior: 0, woman: 0, pwd: 0, property: 0, public: 0 };
    for (const [type, patterns] of Object.entries(VICTIM_PATTERNS)) {
        for (const p of patterns) {
            if (p.includes(' ')) {
                if (description.includes(p)) ctx[type] += 3;
            } else {
                const re = new RegExp(`\\b${escapeRegex(p)}\\b`, 'i');
                if (re.test(description)) ctx[type] += 1;
            }
        }
    }
    return ctx;
}

// ============================================================
// SECTION 4: NON-JURISDICTIONAL DETECTION
// ============================================================
const ESCALATION_PATTERNS = {
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

const ESCALATION_LABELS = {
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

const ESCALATION_REFER_TO_SHORT = {
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

const ESCALATION_SUBJECT = {
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

const ESCALATION_ACTIONS = {
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

function detectEscalation(description) {
    if (!description) return null;
    const normalized = normalizeText(description);
    for (const [type, patterns] of Object.entries(ESCALATION_PATTERNS)) {
        for (const p of patterns) {
            const k = p.toLowerCase();
            const matched = k.includes(' ')
                ? normalized.includes(k)
                : new RegExp(`\\b${escapeRegex(k)}\\b`, 'i').test(normalized);
            if (matched) return type;
        }
    }
    return null;
}

const ANONYMOUS_ELIGIBLE_ESCALATIONS = ['drugs','illegal_gambling','government','rape','vawc','child_abuse','high_penalty'];

// ============================================================
// SECTION 5: SCORING & CLASSIFICATION
// ============================================================
function normalizeText(text) {
    return (text || '').toLowerCase().replace(/[^\w\sáéíóúñ-]/g, ' ').replace(/\s+/g, ' ').trim();
}
function escapeRegex(str) { return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

function countKeywordMatches(description, keywords) {
    if (!keywords || keywords.length === 0) return { score: 0, hits: 0, phraseHits: 0 };
    let score = 0, hits = 0, phraseHits = 0;
    for (const kw of keywords) {
        const k = kw.toLowerCase();
        let matched = false;
        if (k.includes(' ')) {
            if (description.includes(k)) { matched = true; score += 3; phraseHits++; }
        } else {
            const re = new RegExp(`\\b${escapeRegex(k)}\\b`, 'i');
            if (re.test(description)) { matched = true; score += 1; }
        }
        if (matched) hits++;
    }
    return { score, hits, phraseHits };
}

function lawVictimBonus(law, victimCtx) {
    if (!law.victim || law.victim.length === 0) return 0;
    let bonus = 0;
    for (const v of law.victim) {
        if (v === 'any') continue;
        if (victimCtx[v] && victimCtx[v] > 0) bonus += victimCtx[v] * 4;
    }
    return bonus;
}

function classifyDescription(description) {
    if (!description || description.trim().length < 5) return null;
    const normalized = normalizeText(description);
    const victimCtx = detectVictimContext(normalized);

    const lawScores = barangayLaws.map(law => {
        const m = countKeywordMatches(normalized, law.keywords);
        const bonus = lawVictimBonus(law, victimCtx);
        return { law, base: m.score, score: m.score + bonus, hits: m.hits, phraseHits: m.phraseHits };
    }).sort((a, b) => b.score - a.score);

    const topLaw = lawScores[0];
    if (topLaw && topLaw.phraseHits >= 1) return { type: 'law', article: topLaw.law.article, law_title: topLaw.law.title, description: topLaw.law.description || `Pumapatungkol sa ${topLaw.law.title}`, hits: topLaw.hits, confidence: Math.min(98, 78 + topLaw.phraseHits * 6 + topLaw.hits * 2) };
    if (topLaw && topLaw.hits >= 2) return { type: 'law', article: topLaw.law.article, law_title: topLaw.law.title, description: topLaw.law.description || `Pumapatungkol sa ${topLaw.law.title}`, hits: topLaw.hits, confidence: Math.min(92, 62 + topLaw.hits * 8) };
    if (topLaw && topLaw.hits >= 1) return { type: 'law', article: topLaw.law.article, law_title: topLaw.law.title, description: topLaw.law.description || `Pumapatungkol sa ${topLaw.law.title}`, hits: topLaw.hits, confidence: Math.min(85, 55 + topLaw.hits * 8) };
    return null;
}

// ============================================================
// SECTION 5B: ANONYMITY
// ============================================================
function toggleAnonymityWarning() {
    const checkbox = document.getElementById('isAnonymous');
    const card     = document.getElementById('anonymityCard');
    const box      = document.getElementById('anonCheckbox');
    const icon     = document.getElementById('anonCheckIcon');
    const hint     = document.getElementById('anonymityHint');
    const warning  = document.getElementById('anonymityWarning');
    if (!checkbox || !card) return;
    const isAnon = checkbox.checked;

    if (box && icon) {
        if (isAnon) {
            box.style.background = 'linear-gradient(135deg, #2E7D32, #43a047)';
            box.style.borderColor = '#2E7D32';
            box.style.boxShadow = '0 0 0 4px rgba(46,125,50,0.15)';
            icon.style.opacity = '1';
            icon.style.transform = 'scale(1)';
        } else {
            box.style.background = '#fff';
            box.style.borderColor = '#bdbdbd';
            box.style.boxShadow = 'none';
            icon.style.opacity = '0';
            icon.style.transform = 'scale(0.5)';
        }
    }
    if (isAnon) {
        card.style.borderColor = '#2E7D32';
        card.style.background = 'linear-gradient(135deg, #e8f5e9 0%, #f1f8e9 100%)';
        card.style.boxShadow = '0 4px 16px rgba(46,125,50,0.12)';
    } else {
        card.style.borderColor = '#e0e0e0';
        card.style.background = 'linear-gradient(135deg, #fafafa 0%, #f5f5f5 100%)';
        card.style.boxShadow = 'none';
    }
    if (hint && warning) {
        if (isAnon) { hint.style.display = 'block'; warning.style.display = 'none'; }
        else { hint.style.display = 'none'; warning.style.display = 'block'; }
    }
}

function updateAnonymityVisibility(escalationType) {
    const group = document.getElementById('anonymityGroup');
    const checkbox = document.getElementById('isAnonymous');
    if (!group) return;
    const eligible = escalationType && ANONYMOUS_ELIGIBLE_ESCALATIONS.includes(escalationType);
    if (eligible) { group.style.display = 'block'; toggleAnonymityWarning(); }
    else { group.style.display = 'none'; if (checkbox) checkbox.checked = false; toggleAnonymityWarning(); }
}

// ============================================================
// SECTION 6: LAW INFO PANEL
// ============================================================
function showLawInfoPanel(article, title, reason, confidence, extraInfo, exempted, exemptionReason) {
    let panel = document.getElementById('lawInfoPanel');
    const subjectGroup = document.getElementById('complaintSubject')?.closest('.form-group');
    if (!subjectGroup) return;
    if (!panel) { panel = document.createElement('div'); panel.id = 'lawInfoPanel'; subjectGroup.appendChild(panel); }

    if (!article) {
        panel.style.cssText = `margin-top:12px;padding:14px 16px;background:linear-gradient(135deg,#fff8e1 0%,#ffecb3 100%);border-left:4px solid #ef6c00;border-radius:10px;`;
        panel.innerHTML = `<div style="display:flex;gap:10px;"><div style="font-size:24px;">🔍</div><div style="flex:1;"><div style="font-size:11px;color:#ef6c00;font-weight:700;text-transform:uppercase;">Walang Tugmang Batas</div><div style="font-size:14px;color:#333;margin:4px 0;">${escapeHtml(reason || 'Hindi matukoy ang batas. Dagdagan ang detalye.')}</div><div style="font-size:11px;color:#666;"><i class="fas fa-info-circle"></i> Subukang isama: (1) ano ang ginawa, (2) sino ang gumawa, (3) kanino nangyari, (4) kailan at saan.</div></div></div>`;
        return;
    }

    panel.style.cssText = `margin-top:12px;padding:14px 16px;background:linear-gradient(135deg,#e8f5e9 0%,#f1f8e9 100%);border-left:4px solid #2E7D32;border-radius:10px;`;
    panel.innerHTML = `<div style="display:flex;gap:10px;"><div style="font-size:24px;">⚖️</div><div style="flex:1;"><div style="font-size:11px;color:#2E7D32;font-weight:700;text-transform:uppercase;">Katarungang Pambarangay Law</div><div style="font-size:16px;font-weight:700;color:#1B5E20;margin:2px 0;">${escapeHtml(article)}</div><div style="font-size:14px;color:#333;margin-bottom:8px;">${escapeHtml(title)}</div>${reason ? `<div style="font-size:12px;color:#555;background:rgba(255,255,255,0.6);padding:8px;border-radius:6px;margin-bottom:6px;"><strong>Paliwanag:</strong> ${escapeHtml(reason)}</div>` : ''}${extraInfo ? `<div style="font-size:12px;color:#555;background:rgba(255,255,255,0.6);padding:8px;border-radius:6px;margin-bottom:6px;"><strong>Detalye:</strong> ${escapeHtml(extraInfo)}</div>` : ''}<div style="font-size:11px;color:#666;"><span style="background:#2E7D32;color:white;padding:2px 8px;border-radius:10px;font-weight:600;">${confidence}% tugma</span><span style="margin-left:6px;"><i class="fas fa-info-circle"></i> Natukoy mula sa iyong paglalarawan.</span></div></div></div>`;
}

function hideLawInfoPanel() { const panel = document.getElementById('lawInfoPanel'); if (panel) panel.remove(); }

// ============================================================
// SECTION 7: ESCALATION PANEL
// ============================================================
function showEscalationPanel(type) {
    const label = ESCALATION_LABELS[type] || 'Non-Jurisdictional Case';
    const info = ESCALATION_ACTIONS[type] || { referTo: 'Kaukulang ahensya', barangayDuty: ['Itatala ang insidente sa Barangay Blotter.','HINDI maaaring i-mediate.'] };
    let panel = document.getElementById('lawInfoPanel');
    const subjectGroup = document.getElementById('complaintSubject')?.closest('.form-group');
    if (!subjectGroup) return;
    if (!panel) { panel = document.createElement('div'); panel.id = 'lawInfoPanel'; subjectGroup.appendChild(panel); }

    panel.style.cssText = `margin-top:12px;padding:16px;background:linear-gradient(135deg,#ffebee 0%,#ffcdd2 100%);border-left:4px solid #c62828;border-radius:10px;`;
    panel.innerHTML = `<div style="display:flex;gap:12px;"><div style="font-size:32px;line-height:1;">🚨</div><div style="flex:1;"><div style="font-size:11px;color:#c62828;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">HINDI Sakop ng Katarungang Pambarangay</div><div style="font-size:15px;font-weight:700;margin-bottom:6px;color:#b71c1c;">${escapeHtml(label)}</div><div style="font-size:13px;color:#333;margin-bottom:10px;line-height:1.5;">Ang kasong ito ay <strong>hindi maaaring i-mediate</strong> ng Lupong Tagapamayapa. Ito ay kabilang sa mga kasong hindi sakop ng Barangay Justice System (RA 7160, §408-422).</div><div style="font-size:12px;color:#333;background:rgba(255,255,255,0.75);padding:10px 12px;border-radius:8px;margin-bottom:8px;"><strong style="color:#c62828;">I-refer sa:</strong> ${escapeHtml(info.referTo)}</div><div style="font-size:12px;color:#333;background:rgba(255,255,255,0.75);padding:10px 12px;border-radius:8px;line-height:1.6;"><strong style="color:#1B5E20;display:block;margin-bottom:6px;">Ano ang gagawin ng Barangay:</strong><ol style="margin:0;padding-left:20px;">${info.barangayDuty.map(a => `<li>${a}</li>`).join('')}</ol></div><div style="font-size:11px;color:#666;margin-top:8px;"><i class="fas fa-info-circle"></i> Ang Barangay ay <strong>magtatala, magre-refer, at magpoprotekta</strong> — ngunit <strong>hindi magme-mediate</strong>.</div></div></div>`;
}

// ============================================================
// SECTION 8: MANUAL DROPDOWN
// ============================================================
document.addEventListener('change', function(e) {
    if (!e.target || e.target.id !== 'complaintSubject') return;
    if (suppressSubjectChangeEvent) { suppressSubjectChangeEvent = false; return; }
    if (!userInteractedWithSubject) return;
    userInteractedWithSubject = false;
    const selected = e.target.options[e.target.selectedIndex];
    if (!selected) return;
    const article = selected.dataset.article;
    const lawTitle = selected.dataset.lawTitle;
    const subjectValue = e.target.value;
    if (article && lawTitle) showLawInfoPanel(article, lawTitle, 'Manu-manong pinili ang batas.', 100, '', false, '');
    else if (standardToLaw[subjectValue] && standardToLaw[subjectValue].article) showLawInfoPanel(standardToLaw[subjectValue].article, standardToLaw[subjectValue].title, 'Karaniwang uri ng reklamo na may katumbas na batas.', 100, '', false, '');
    else hideLawInfoPanel();
});

// ============================================================
// SECTION 9: MAIN DESCRIPTION HANDLER
// ============================================================
let analysisTimeout = null;
function onDescriptionChange() {
    const descInput = document.getElementById('complaintDescription');
    if (!descInput) return;
    const description = descInput.value.trim();
    const subjectSelect = document.getElementById('complaintSubject');
    if (!subjectSelect) return;

    if (description.length < 5) {
        subjectSelect.innerHTML = '<option value="" disabled selected>┅ I-type ang paglalarawan para makita ang mungkahi ┅</option>';
        hideLawInfoPanel();
        clearTitleSuggestion();
        updateAnonymityVisibility(null);
        return;
    }
    if (analysisTimeout) clearTimeout(analysisTimeout);
    analysisTimeout = setTimeout(() => { applyClassification(description); }, 400);
}

function applyClassification(description) {
    const subjectSelect = document.getElementById('complaintSubject');
    if (!subjectSelect) return;
    const escalation = detectEscalation(description);
    if (escalation) {
        const subject = ESCALATION_SUBJECT[escalation] || 'Other Concern';
        const referToShort = ESCALATION_REFER_TO_SHORT[escalation] || 'Proper Government Agency';
        const label = ESCALATION_LABELS[escalation] || 'Non-Jurisdictional Case';
        subjectSelect.innerHTML = '';
        const opt = document.createElement('option');
        opt.value = subject;
        opt.textContent = `🚨 ${subject} — ESCALATED (${label})`;
        opt.dataset.escalated = escalation;
        opt.dataset.referTo = referToShort;
        subjectSelect.appendChild(opt);
        standardSubjects.forEach(s => { if (s === subject) return; const alt = document.createElement('option'); alt.value = s; alt.textContent = s; subjectSelect.appendChild(alt); });
        suppressSubjectChangeEvent = true;
        subjectSelect.value = subject;
        showEscalationPanel(escalation);
        const titleInput = document.getElementById('complaintTitle');
        if (titleInput) { titleInput.value = `ESCALATED: ${label} (Refer to ${referToShort})`; titleInput.dataset.escalated = escalation; }
        updateAnonymityVisibility(escalation);
        if (document.getElementById('isAnonymous')) toggleAnonymityWarning();
        return;
    }
    const titleInput0 = document.getElementById('complaintTitle');
    if (titleInput0) delete titleInput0.dataset.escalated;
    updateAnonymityVisibility(null);

    const result = classifyDescription(description);
    if (!result) {
        subjectSelect.innerHTML = '<option value="" disabled selected>┅ Walang tugmang batas — dagdagan ang detalye ┅</option>';
        showLawInfoPanel(null, null, 'Hindi matukoy ang batas mula sa iyong paglalarawan. Subukang magdagdag ng detalye.', 0, '', false, '');
        return;
    }
    if (result.type === 'law') {
        subjectSelect.innerHTML = '';
        const lawOpt = document.createElement('option');
        lawOpt.value = `${result.article} - ${result.law_title}`;
        lawOpt.textContent = `⚖️ ${result.article} - ${result.law_title}`;
        lawOpt.dataset.article = result.article;
        lawOpt.dataset.lawTitle = result.law_title;
        subjectSelect.appendChild(lawOpt);
        suppressSubjectChangeEvent = true;
        subjectSelect.value = lawOpt.value;
        showLawInfoPanel(result.article, result.law_title, result.description, result.confidence, '', false, '');
        const titleInput = document.getElementById('complaintTitle');
        if (titleInput) titleInput.value = `${result.article}: ${result.law_title}`;
    }
}

// ============================================================
// SECTION 10: TITLE SUGGESTION
// ============================================================
function showTitleSuggestion(suggestedTitle) {
    const titleGroup = document.getElementById('complaintTitle')?.closest('.form-group');
    if (!titleGroup) return;
    const existing = document.getElementById('titleSuggestion');
    if (existing) existing.remove();
    const div = document.createElement('div');
    div.id = 'titleSuggestion';
    div.style.cssText = 'margin-top:8px;padding:8px 12px;background:#e3f2fd;border-radius:8px;border-left:3px solid #2196f3;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;';
    div.innerHTML = `<div><i class="fas fa-magic" style="color:#2196f3;"></i> <span style="font-size:13px;">Mungkahi: <strong>${escapeHtml(suggestedTitle)}</strong></span></div><button type="button" onclick="applyTitleSuggestion('${escapeHtml(suggestedTitle).replace(/'/g, "\\'")}')" style="background:#2196f3;color:white;border:none;padding:4px 12px;border-radius:20px;cursor:pointer;font-size:11px;"><i class="fas fa-check"></i> Gamitin</button>`;
    titleGroup.appendChild(div);
}
function applyTitleSuggestion(title) {
    const input = document.getElementById('complaintTitle');
    if (input) { input.value = title; const s = document.getElementById('titleSuggestion'); if (s) s.remove(); input.style.border = '2px solid #28a745'; setTimeout(() => input.style.border = '', 1500); }
}
function clearTitleSuggestion() { const s = document.getElementById('titleSuggestion'); if (s) s.remove(); }

// ============================================================
// SECTION 11: INCIDENTS
// ============================================================
function renderIncidentsList() {
    if (incidentsList.length === 0) incidentsList = [{ incident_date: '', incident_time: '', location: '', description: '', evidence_files: [] }];
    return incidentsList.map((incident, index) => `
        <div class="incident-card" data-incident-index="${index}">
            <div class="incident-header"><strong><i class="fas fa-calendar-alt"></i> Incident ${index + 1}</strong><div><button type="button" class="add-evidence-btn" onclick="addIncidentEvidence(${index})"><i class="fas fa-paperclip"></i> Add Evidence</button>${incidentsList.length > 1 ? `<button type="button" class="remove-incident-btn" onclick="removeIncident(${index})"><i class="fas fa-trash"></i> Remove</button>` : ''}</div></div>
            <div class="incident-body">
                <div class="form-row"><div class="form-group"><label>Date of Incident <span style="color:red;">*</span></label><input type="date" class="incident-date" data-incident-index="${index}" value="${incident.incident_date || ''}"></div><div class="form-group"><label>Time of Incident</label><input type="time" class="incident-time" data-incident-index="${index}" value="${incident.incident_time || ''}"></div></div>
                <div class="form-group"><label>Location of Incident <span style="color:red;">*</span></label><input type="text" class="incident-location" data-incident-index="${index}" value="${escapeHtml(incident.location || '')}" placeholder="e.g., Blk 12 Lot 5, Purok 3"></div>
                <div class="form-group"><label>Incident Description <span style="color:red;">*</span></label><textarea class="incident-description" data-incident-index="${index}" rows="3" placeholder="Describe what happened...">${escapeHtml(incident.description || '')}</textarea></div>
                <div class="evidence-section"><label><i class="fas fa-paperclip"></i> Evidence</label><div class="evidence-files-container" id="evidence-files-${index}">${renderEvidenceFiles(incident.evidence_files, index)}</div><input type="file" class="evidence-file-input" data-incident-index="${index}" multiple accept=".jpg,.jpeg,.png,.pdf,.mp4" style="display:none;"></div>
            </div>
        </div>`).join('');
}

function renderEvidenceFiles(files, incidentIndex) {
    if (!files || files.length === 0) return '<div class="no-evidence">No evidence yet.</div>';
    return files.map((file, i) => `<div class="evidence-file-item"><i class="fas ${file.type?.startsWith('image/') ? 'fa-image' : file.type?.includes('pdf') ? 'fa-file-pdf' : 'fa-file'}"></i><span class="file-name">${escapeHtml(file.name)}</span><button type="button" class="remove-evidence-btn" onclick="removeIncidentEvidence(${incidentIndex}, ${i})"><i class="fas fa-times"></i></button></div>`).join('');
}

function addIncident() { incidentsList.push({ incident_date: '', incident_time: '', location: '', description: '', evidence_files: [] }); document.getElementById('incidentsContainer').innerHTML = renderIncidentsList(); attachIncidentEventListeners(); attachFieldAutoClear(); }
function removeIncident(i) { incidentsList.splice(i, 1); document.getElementById('incidentsContainer').innerHTML = renderIncidentsList(); attachIncidentEventListeners(); attachFieldAutoClear(); }
function addIncidentEvidence(i) { document.querySelector(`.evidence-file-input[data-incident-index="${i}"]`).click(); }
function removeIncidentEvidence(i, fi) { incidentsList[i].evidence_files.splice(fi, 1); document.getElementById(`evidence-files-${i}`).innerHTML = renderEvidenceFiles(incidentsList[i].evidence_files, i); }

function attachIncidentEventListeners() {
    document.querySelectorAll('.incident-date, .incident-time, .incident-location, .incident-description').forEach(input => {
        input.removeEventListener('change', updateIncidentField);
        input.removeEventListener('input', updateIncidentField);
        input.addEventListener(input.type === 'date' || input.type === 'time' ? 'change' : 'input', updateIncidentField);
    });
    document.querySelectorAll('.evidence-file-input').forEach(input => {
        input.removeEventListener('change', handleIncidentFileUpload);
        input.addEventListener('change', handleIncidentFileUpload);
    });
}

function updateIncidentField(e) {
    const input = e.target;
    const idx = parseInt(input.dataset.incidentIndex);
    let field = '';
    if (input.classList.contains('incident-date')) field = 'incident_date';
    else if (input.classList.contains('incident-time')) field = 'incident_time';
    else if (input.classList.contains('incident-location')) field = 'location';
    else if (input.classList.contains('incident-description')) field = 'description';
    if (incidentsList[idx] && field) incidentsList[idx][field] = input.value;
}

function handleIncidentFileUpload(e) {
    const input = e.target;
    const idx = parseInt(input.dataset.incidentIndex);
    const files = Array.from(input.files);
    if (!incidentsList[idx]) return;
    if (!incidentsList[idx].evidence_files) incidentsList[idx].evidence_files = [];
    files.forEach(f => {
        if (f.size > 10 * 1024 * 1024) { showErrorModal(`File ${f.name} exceeds 10MB`); return; }
        incidentsList[idx].evidence_files.push({ name: f.name, size: f.size, type: f.type, file: f });
    });
    document.getElementById(`evidence-files-${idx}`).innerHTML = renderEvidenceFiles(incidentsList[idx].evidence_files, idx);
    input.value = '';
}

// ============================================================
// SECTION 12: PARTY MANAGEMENT
// ============================================================
function renderComplainantsList() {
    if (complainantsList.length === 0) return '<div class="empty-list">No complainants yet.</div>';
    return complainantsList.map((p, i) => `<div class="party-card" data-index="${i}" data-type="complainant"><div class="party-header"><strong><i class="fas fa-user"></i> Complainant ${i+1}</strong><button type="button" class="remove-party-btn" onclick="removeComplainant(${i})"><i class="fas fa-trash"></i> Remove</button></div><div class="party-body"><div class="form-row"><div class="form-group"><label>Full Name <span style="color:red;">*</span></label><input type="text" class="party-name" data-index="${i}" data-field="full_name" value="${escapeHtml(p.full_name)}" required></div><div class="form-group"><label>Contact Number</label><input type="tel" class="party-contact" data-index="${i}" data-field="contact_number" value="${escapeHtml(p.contact_number || '')}"></div></div><div class="form-row"><div class="form-group"><label>Email</label><input type="email" class="party-email" data-index="${i}" data-field="email" value="${escapeHtml(p.email || '')}"></div><div class="form-group"><label>Address</label><input type="text" class="party-address" data-index="${i}" data-field="address" value="${escapeHtml(p.address || '')}"></div></div></div></div>`).join('');
}

function renderRespondentsList() {
    if (respondentsList.length === 0) return '<div class="empty-list">No respondents yet.</div>';
    return respondentsList.map((p, i) => `<div class="party-card" data-index="${i}" data-type="respondent"><div class="party-header"><strong><i class="fas fa-user-friends"></i> Respondent ${i+1}</strong><button type="button" class="remove-party-btn" onclick="removeRespondent(${i})"><i class="fas fa-trash"></i> Remove</button></div><div class="party-body"><div class="form-row"><div class="form-group"><label>Full Name <span style="color:red;">*</span></label><input type="text" class="party-name" data-index="${i}" data-field="full_name" value="${escapeHtml(p.full_name)}" required></div><div class="form-group"><label>Contact Number</label><input type="tel" class="party-contact" data-index="${i}" data-field="contact_number" value="${escapeHtml(p.contact_number || '')}"></div></div><div class="form-row"><div class="form-group"><label>Address</label><input type="text" class="party-address" data-index="${i}" data-field="address" value="${escapeHtml(p.address || '')}"></div><div class="form-group"><label>Relationship</label><input type="text" class="party-relationship" data-index="${i}" data-field="relationship" value="${escapeHtml(p.relationship || '')}" placeholder="e.g., Neighbor"></div></div></div></div>`).join('');
}

function renderWitnessesList() {
    if (witnessesList.length === 0) return '<div class="empty-list">No witnesses yet.</div>';
    return witnessesList.map((p, i) => `<div class="party-card" data-index="${i}" data-type="witness"><div class="party-header"><strong><i class="fas fa-eye"></i> Witness ${i+1}</strong><button type="button" class="remove-party-btn" onclick="removeWitness(${i})"><i class="fas fa-trash"></i> Remove</button></div><div class="party-body"><div class="form-row"><div class="form-group"><label>Full Name</label><input type="text" class="party-name" data-index="${i}" data-field="full_name" value="${escapeHtml(p.full_name || '')}"></div><div class="form-group"><label>Contact Number</label><input type="tel" class="party-contact" data-index="${i}" data-field="contact_number" value="${escapeHtml(p.contact_number || '')}"></div></div><div class="form-group"><label>Address</label><input type="text" class="party-address" data-index="${i}" data-field="address" value="${escapeHtml(p.address || '')}"></div></div></div>`).join('');
}

function addComplainant() { complainantsList.push({ full_name: '', address: '', contact_number: '', email: '' }); document.getElementById('complainantsContainer').innerHTML = renderComplainantsList(); attachPartyEventListeners(); attachFieldAutoClear(); }
function removeComplainant(i) { complainantsList.splice(i, 1); document.getElementById('complainantsContainer').innerHTML = renderComplainantsList(); attachPartyEventListeners(); attachFieldAutoClear(); }
function addRespondent() { respondentsList.push({ full_name: '', address: '', contact_number: '', relationship: '' }); document.getElementById('respondentsContainer').innerHTML = renderRespondentsList(); attachPartyEventListeners(); attachFieldAutoClear(); }
function removeRespondent(i) { respondentsList.splice(i, 1); document.getElementById('respondentsContainer').innerHTML = renderRespondentsList(); attachPartyEventListeners(); attachFieldAutoClear(); }
function addWitness() { witnessesList.push({ full_name: '', address: '', contact_number: '' }); document.getElementById('witnessesContainer').innerHTML = renderWitnessesList(); attachPartyEventListeners(); attachFieldAutoClear(); }
function removeWitness(i) { witnessesList.splice(i, 1); document.getElementById('witnessesContainer').innerHTML = renderWitnessesList(); attachPartyEventListeners(); attachFieldAutoClear(); }

function attachPartyEventListeners() {
    ['#complainantsContainer', '#respondentsContainer', '#witnessesContainer'].forEach(sel => {
        document.querySelectorAll(`${sel} .party-name, ${sel} .party-contact, ${sel} .party-email, ${sel} .party-address, ${sel} .party-relationship`).forEach(input => {
            input.removeEventListener('input', updatePartyField);
            input.addEventListener('input', updatePartyField);
        });
    });
}

function updatePartyField(e) {
    const input = e.target;
    const idx = parseInt(input.dataset.index);
    const field = input.dataset.field;
    const type = input.closest('.party-card')?.dataset.type;
    let arr;
    if (type === 'complainant') arr = complainantsList;
    else if (type === 'respondent') arr = respondentsList;
    else if (type === 'witness') arr = witnessesList;
    else return;
    if (arr && arr[idx]) arr[idx][field] = input.value;
}

// ============================================================
// SECTION 13: SUBMIT COMPLAINT
// ============================================================
async function submitComplaint(event) {
    event.preventDefault();
    const titleEl = document.getElementById('complaintTitle');
    const descEl = document.getElementById('complaintDescription');
    const subjectEl = document.getElementById('complaintSubject');
    const title = titleEl.value.trim();
    const desc = descEl.value.trim();
    const subject = subjectEl.value;
    const escalation = detectEscalation(desc);
    const isEscalated = !!escalation;
    const referToShort = isEscalated ? (ESCALATION_REFER_TO_SHORT[escalation] || 'Proper Government Agency') : '';

    if (isEscalated && (!subject || subjectEl.selectedOptions[0]?.dataset?.escalated)) {
        const mappedSubject = ESCALATION_SUBJECT[escalation] || 'Other Concern';
        subjectEl.value = mappedSubject;
    }

    clearAllFieldErrors();
    let firstInvalidEl = null;
    let errorCount = 0;
    if (!subject) { markFieldError(subjectEl, 'Please select a complaint subject.'); if (!firstInvalidEl) firstInvalidEl = subjectEl; errorCount++; }
    if (!title) { markFieldError(titleEl, 'Complaint title is required.'); if (!firstInvalidEl) firstInvalidEl = titleEl; errorCount++; }
    if (!desc) { markFieldError(descEl, 'Description is required.'); if (!firstInvalidEl) firstInvalidEl = descEl; errorCount++; }
    else if (desc.length < 10) { markFieldError(descEl, 'Description must be at least 10 characters.'); if (!firstInvalidEl) firstInvalidEl = descEl; errorCount++; }
    if (complainantsList.length === 0 || !(complainantsList[0].full_name || '').trim()) { const card = document.querySelector('#complainantsContainer .party-card'); if (card) { markGroupError(card, 'At least one complainant with full name is required.'); const nameInput = card.querySelector('.party-name'); if (nameInput) { nameInput.classList.add('field-error'); if (!firstInvalidEl) firstInvalidEl = nameInput; } } errorCount++; }
    if (respondentsList.length === 0 || !(respondentsList[0].full_name || '').trim()) { const card = document.querySelector('#respondentsContainer .party-card'); if (card) { markGroupError(card, 'At least one respondent with full name is required.'); const nameInput = card.querySelector('.party-name'); if (nameInput) { nameInput.classList.add('field-error'); if (!firstInvalidEl) firstInvalidEl = nameInput; } } errorCount++; }

    let hasValidIncident = false;
    let invalidIncidentCount = 0;
    incidentsList.forEach((inc, idx) => {
        const card = document.querySelector(`.incident-card[data-incident-index="${idx}"]`);
        if (!card) return;
        const dateInput = card.querySelector('.incident-date');
        const locInput = card.querySelector('.incident-location');
        const descInput = card.querySelector('.incident-description');
        let incValid = true;
        if (!inc.incident_date) { if (dateInput) markFieldError(dateInput, 'Date required.'); incValid = false; }
        if (!inc.location) { if (locInput) markFieldError(locInput, 'Location required.'); incValid = false; }
        if (!inc.description) { if (descInput) markFieldError(descInput, 'Description required.'); incValid = false; }
        if (incValid) hasValidIncident = true;
        else { invalidIncidentCount++; markGroupError(card, 'Please complete this incident.'); if (!firstInvalidEl) firstInvalidEl = card; }
    });
    if (!hasValidIncident) { errorCount += (invalidIncidentCount || 1); const firstCard = document.querySelector('#incidentsContainer .incident-card'); if (firstCard && invalidIncidentCount === 0) { markGroupError(firstCard, 'Please add at least one complete incident.'); if (!firstInvalidEl) firstInvalidEl = firstCard; } }
    if (errorCount > 0) { if (firstInvalidEl) firstInvalidEl.scrollIntoView({ behavior: 'smooth', block: 'center' }); attachFieldAutoClear(); return; }

    const submitBtn = document.querySelector('#complaintForm button[type="submit"]');
    if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...'; }

    let escalationNote = '';
    if (isEscalated) escalationNote = `[ESCALATED CASE — NOT under Katarungang Pambarangay] Reason: ${ESCALATION_LABELS[escalation]}. Refer to: ${referToShort}. The Barangay will record this in the blotter, issue a Referral Letter, and coordinate with the proper agency. NO MEDIATION will be conducted.`;

    const formData = new FormData();
    formData.append('action', 'submit_complaint');
    formData.append('title', title);
    formData.append('description', desc);
    formData.append('complaint_subject', subject);
    formData.append('priority', isEscalated ? 'urgent' : document.getElementById('priority').value);
    formData.append('additional_notes', document.getElementById('additionalNotes').value);
    formData.append('complainants', JSON.stringify(complainantsList));
    formData.append('respondents', JSON.stringify(respondentsList));
    if (witnessesList.length) formData.append('witnesses', JSON.stringify(witnessesList));
    if (isEscalated) { formData.append('escalation_type', escalation); formData.append('escalation_refer_to', referToShort); formData.append('escalation_note', escalationNote); }

    const anonGroup = document.getElementById('anonymityGroup');
    const anonCheckbox = document.getElementById('isAnonymous');
    const anonVisible = anonGroup && anonGroup.style.display !== 'none';
    const isAnonymousValue = (anonVisible && anonCheckbox && anonCheckbox.checked) ? 1 : 0;
    formData.append('is_anonymous', isAnonymousValue);

    const incidentsData = incidentsList.map(inc => ({ incident_date: inc.incident_date, incident_time: inc.incident_time, location: inc.location, description: inc.description }));
    formData.append('incidents', JSON.stringify(incidentsData));

    for (let i = 0; i < incidentsList.length; i++) { const evFiles = incidentsList[i].evidence_files; if (evFiles && evFiles.length) { for (const f of evFiles) { if (f.file) formData.append(`incident_evidence_${i}[]`, f.file); } } }

    showLoading(isEscalated ? 'Saving escalated case...' : 'Submitting complaint...');
    try {
        const resp = await fetch('complaint_ajax.php', { method: 'POST', body: formData });
        const text = await resp.text();
        let data;
        try { data = JSON.parse(text); } catch (parseErr) { throw new Error('Server returned invalid response.'); }
        hideLoading();
        if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Complaint'; }
        if (data.success) {
            if (isEscalated) showEscalationSuccessModal(data.reference_number, escalation, isAnonymousValue === 1);
            else showComplaintSuccessModal(data.reference_number, () => { resetComplaintForm(); showMyComplaints(); });
        } else showErrorModal(data.message || 'Failed to submit');
    } catch (err) { hideLoading(); if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Complaint'; } showErrorModal('An error occurred: ' + err.message); }
}

function showEscalationSuccessModal(referenceNumber, escalationType, isAnonymous) {
    const referTo = ESCALATION_REFER_TO_SHORT[escalationType] || 'Proper Government Agency';
    const label = ESCALATION_LABELS[escalationType] || 'Non-Jurisdictional Case';
    ensureModalStyles();
    const existing = document.getElementById('appModalOverlay');
    if (existing) existing.remove();
    const anonBlock = isAnonymous ? `<div style="font-size:12px;color:#333;background:#fff8e1;padding:10px 12px;border-radius:8px;border-left:3px solid #ef6c00;margin-bottom:10px;line-height:1.6;"><strong style="display:block;margin-bottom:4px;color:#e65100;"><i class="fas fa-user-shield"></i> Whistleblower Protection Active</strong>Your identity is protected. The Barangay and all referral documents will show you only as <strong>"Concerned Resident"</strong>.</div>` : '';
    const overlay = document.createElement('div');
    overlay.id = 'appModalOverlay';
    overlay.className = 'app-modal-overlay';
    overlay.innerHTML = `<div class="app-modal-box" style="max-width:480px;text-align:left;"><div style="text-align:center;"><div class="app-modal-icon warning"><i class="fas fa-exclamation-triangle"></i></div><div class="app-modal-title">Case Recorded — Referral Required</div></div><div style="font-size:13px;color:#333;line-height:1.6;margin:10px 0;">Ang inyong kaso ay <strong>naitala na sa Barangay</strong> para sa rekord at referral.</div><div style="font-size:12px;color:#333;background:#fff8e1;padding:10px 12px;border-radius:8px;border-left:3px solid #ef6c00;margin-bottom:10px;"><strong>Klase ng kaso:</strong> ${escapeHtml(label)}<br><strong>I-refer sa:</strong> ${escapeHtml(referTo)}<br><strong>Status:</strong> ESCALATED (hindi dadaan sa mediation)</div>${anonBlock}<div style="text-align:center;margin:14px 0;"><div style="font-size:11px;color:#666;margin-bottom:4px;">Reference Number</div><div class="app-modal-ref" style="margin-top:0;">${escapeHtml(referenceNumber)}</div></div><div style="font-size:12px;color:#333;background:#e8f5e9;padding:10px 12px;border-radius:8px;border-left:3px solid #2E7D32;margin-bottom:10px;line-height:1.6;"><strong style="display:block;margin-bottom:4px;color:#2E7D32;">Ano ang susunod na gagawin:</strong><ol style="margin:0;padding-left:20px;"><li>Pumunta sa Barangay Hall upang kunin ang <strong>Referral Letter</strong>.</li><li>Dalhin ang referral sa <strong>${escapeHtml(referTo)}</strong>.</li><li>Kung may agarang panganib, tumawag agad sa <strong>911</strong>.</li><li>Kung VAWC, hilingin ang <strong>Barangay Protection Order (BPO)</strong>.</li></ol></div><div style="font-size:11px;color:#666;text-align:center;"><i class="fas fa-info-circle"></i> Ang Barangay ay hindi magme-mediate sa kasong ito ayon sa batas.</div><button type="button" class="app-modal-btn warning" id="appModalBtn" style="margin-top:16px;">Naiintindihan ko</button></div>`;
    document.body.appendChild(overlay);
    return new Promise((resolve) => { const close = () => { overlay.remove(); resetComplaintForm(); showMyComplaints(); resolve(true); }; document.getElementById('appModalBtn').addEventListener('click', close); overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); }); });
}

// ============================================================
// SECTION 14: MAIN FORM
// ============================================================
function getSubmitComplaintForm() {
    const resident = window.resident || {};
    const rName = resident.name || resident.firstName + ' ' + resident.lastName || '';
    const rPhone = resident.phone || '';
    const rEmail = resident.email || '';
    const rAddr = resident.address || '';
    if (complainantsList.length === 0) complainantsList = [{ full_name: rName, address: rAddr, contact_number: rPhone, email: rEmail }];
    if (incidentsList.length === 0) incidentsList = [{ incident_date: '', incident_time: '', location: '', description: '', evidence_files: [] }];

    return `
        <form id="complaintForm" onsubmit="submitComplaint(event)" novalidate>
            <div class="form-section">
                <h3><i class="fas fa-info-circle"></i> Complaint Information</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> Complaint Subject <span style="color:red;">*</span></label>
                        <select id="complaintSubject" class="form-control" required>
                            <option value="" disabled selected>┅ I-type ang paglalarawan para makita ang mungkahi ┅</option>
                        </select>
                        <small style="color:#2eaa5e;"><i class="fas fa-list"></i> Base sa 45 batas ng Katarungang Pambarangay</small>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-exclamation-triangle"></i> Complaint Title <span style="color:red;">*</span></label>
                        <input type="text" id="complaintTitle" class="form-control" required placeholder="Auto-filled from description">
                    </div>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-align-left"></i> Overall Description <span style="color:red;">*</span></label>
                    <textarea id="complaintDescription" class="form-control" rows="4" required placeholder="Ilarawan nang detalyado ang reklamo. Isama: ano ang ginawa, sino ang gumawa, kanino nangyari, kailan at saan."></textarea>
                    <small style="color:#2eaa5e;"><i class="fas fa-magic"></i> Ang sistema ay tumutukoy ng batas base sa buong paglalarawan.</small>
                </div>
                <div class="form-group" id="anonymityGroup" style="display: none; margin-top: 18px;">
                    <div id="anonymityCard" style="position: relative; border: 2px solid #e0e0e0; border-radius: 14px; padding: 18px 20px; background: linear-gradient(135deg, #fafafa 0%, #f5f5f5 100%); transition: all 0.25s ease; cursor: pointer;" onclick="if(event.target.tagName !== 'INPUT' && event.target.tagName !== 'A' && event.target.tagName !== 'I' && !event.target.closest('a')) { document.getElementById('isAnonymous').click(); }">
                        <div style="display: flex; align-items: flex-start; gap: 14px;">
                            <div id="anonCheckbox" style="flex-shrink: 0; width: 24px; height: 24px; border-radius: 6px; border: 2px solid #bdbdbd; background: #fff; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease; margin-top: 1px;">
                                <i class="fas fa-check" id="anonCheckIcon" style="color: #fff; font-size: 13px; opacity: 0; transform: scale(0.5); transition: all 0.2s ease;"></i>
                            </div>
                            <input type="checkbox" id="isAnonymous" name="is_anonymous" value="1" onchange="toggleAnonymityWarning()" style="position: absolute; opacity: 0; pointer-events: none;">
                            <div style="flex: 1; min-width: 0;">
                                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                    <i class="fas fa-user-shield" style="color: #e65100; font-size: 1rem;"></i>
                                    <span style="font-weight: 700; font-size: 0.95rem; color: #5d4037;">File Anonymously</span>
                                    <span style="background: linear-gradient(135deg, #e65100, #ef6c00); color: #fff; font-size: 0.62rem; font-weight: 700; padding: 2px 8px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.5px;">Whistleblower</span>
                                </div>
                                <div style="font-size: 0.82rem; color: #666; margin-top: 4px; line-height: 1.5;">Your identity will be hidden. The Barangay will see you only as <strong style="color: #5d4037;">"Concerned Resident"</strong>.</div>
                            </div>
                        </div>
                        <div id="anonymityHint" style="display: none; margin-top: 14px; padding-top: 14px; border-top: 1px dashed #d0d0d0;">
                            <div style="display: flex; gap: 12px; font-size: 0.8rem; line-height: 1.6;">
                                <div style="flex-shrink: 0; width: 26px; height: 26px; border-radius: 50%; background: #e8f5e9; display: flex; align-items: center; justify-content: center;"><i class="fas fa-shield-alt" style="color: #2E7D32; font-size: 12px;"></i></div>
                                <div style="color: #333;"><div style="font-weight: 700; color: #2E7D32; margin-bottom: 3px;">Protection Active</div><ul style="margin: 0; padding-left: 18px; color: #555;"><li>Your name won't appear on the referral letter or any official document</li><li>You can still track this case using your account</li><li>The Barangay can contact you privately if needed</li></ul></div>
                            </div>
                        </div>
                        <div id="anonymityWarning" style="display: none; margin-top: 14px; padding-top: 14px; border-top: 1px dashed #d0d0d0;">
                            <div style="display: flex; gap: 12px; font-size: 0.8rem; line-height: 1.6;">
                                <div style="flex-shrink: 0; width: 26px; height: 26px; border-radius: 50%; background: #fff3e0; display: flex; align-items: center; justify-content: center;"><i class="fas fa-info-circle" style="color: #ef6c00; font-size: 12px;"></i></div>
                                <div style="color: #555;">If you don't check this, your name and contact details will appear on the official record.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="form-section">
                <h3><i class="fas fa-calendar-alt"></i> Incidents <button type="button" class="add-item-btn" onclick="addIncident()" style="float:right;"><i class="fas fa-plus"></i> Add Another Incident</button></h3>
                <div id="incidentsContainer">${renderIncidentsList()}</div>
            </div>
            <div class="form-section">
                <h3><i class="fas fa-users"></i> Complainants <button type="button" class="add-item-btn" onclick="addComplainant()" style="float:right;"><i class="fas fa-plus"></i> Add Another</button></h3>
                <div id="complainantsContainer">${renderComplainantsList()}</div>
            </div>
            <div class="form-section">
                <h3><i class="fas fa-user-friends"></i> Respondents <button type="button" class="add-item-btn" onclick="addRespondent()" style="float:right;"><i class="fas fa-plus"></i> Add Another</button></h3>
                <div id="respondentsContainer">${renderRespondentsList()}</div>
            </div>
            <div class="form-section">
                <h3><i class="fas fa-eye"></i> Witnesses <button type="button" class="add-item-btn" onclick="addWitness()" style="float:right;"><i class="fas fa-plus"></i> Add Witness</button></h3>
                <div id="witnessesContainer">${renderWitnessesList()}</div>
            </div>
            <div class="form-section">
                <h3><i class="fas fa-flag"></i> Priority & Additional Notes</h3>
                <div class="form-group"><label>Priority Level</label><select id="priority" class="form-control"><option value="low">Low - Minor issue</option><option value="medium" selected>Medium - Moderate concern</option><option value="high">High - Urgent attention</option><option value="urgent">Urgent - Immediate action</option></select></div>
                <div class="form-group"><label>Additional Notes</label><textarea id="additionalNotes" class="form-control" rows="2" placeholder="Any other information..."></textarea></div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn-verify"><i class="fas fa-paper-plane"></i> Submit Complaint</button>
                <button type="button" class="btn-close" onclick="resetComplaintForm()"><i class="fas fa-eraser"></i> Reset Form</button>
            </div>
        </form>
    `;
}

// ============================================================
// SECTION 15: MY COMPLAINTS — Tabs + Filter + CARD VIEW
// ============================================================
async function showMyComplaints() {
    if (!ensureComplaintContainer()) return;
    const container = document.getElementById('complaintContent');
    container.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading complaints...</p></div>';
    try {
        const resp = await fetch('complaint_ajax.php?action=get_my_complaints');
        const data = await resp.json();
        if (data.success) {
            cachedComplaints.as_complainant = data.as_complainant || [];
            cachedComplaints.as_respondent  = data.as_respondent  || [];
            currentStatusFilter = 'all';
            renderComplaintsWithTabs();
        } else {
            container.innerHTML = `<div class="empty-state"><i class="fas fa-folder-open fa-3x"></i><p>No complaints yet.</p><button class="btn-verify" onclick="showSubmitComplaintForm()"><i class="fas fa-plus"></i> File a Complaint</button></div>`;
        }
    } catch (err) {
        container.innerHTML = '<div class="empty-state"><p>Error loading complaints.</p></div>';
    }
}

function computeStatusCounts(list) {
    const counts = { all: list.length };
    list.forEach(c => { counts[c.status] = (counts[c.status] || 0) + 1; });
    return counts;
}

function renderComplaintsWithTabs() {
    const container = document.getElementById('complaintContent');
    const asC = cachedComplaints.as_complainant || [];
    const asR = cachedComplaints.as_respondent  || [];
    const currentList = currentComplaintTab === 'complainant' ? asC : asR;
    const statusCounts = computeStatusCounts(currentList);

    // Tab buttons
    const tabsHtml = `
        <div style="display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap;align-items:center;">
            <button type="button" onclick="switchComplaintTab('complainant')"
                    style="padding:10px 18px;border-radius:22px;border:1.5px solid #e2efe8;
                           background:${currentComplaintTab === 'complainant' ? '#2E7D32' : '#fff'};
                           color:${currentComplaintTab === 'complainant' ? '#fff' : '#555'};
                           font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:8px;">
                <i class="fas fa-user"></i> As Complainant
                <span style="background:${currentComplaintTab === 'complainant' ? 'rgba(255,255,255,0.3)' : '#f0f0f0'};padding:2px 8px;border-radius:10px;font-size:0.72rem;">${asC.length}</span>
            </button>
            <button type="button" onclick="switchComplaintTab('respondent')"
                    style="padding:10px 18px;border-radius:22px;border:1.5px solid #e2efe8;
                           background:${currentComplaintTab === 'respondent' ? '#e65100' : '#fff'};
                           color:${currentComplaintTab === 'respondent' ? '#fff' : '#555'};
                           font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:8px;">
                <i class="fas fa-user-friends"></i> As Respondent
                <span style="background:${currentComplaintTab === 'respondent' ? 'rgba(255,255,255,0.3)' : '#f0f0f0'};padding:2px 8px;border-radius:10px;font-size:0.72rem;">${asR.length}</span>
            </button>
            <button type="button" class="btn-verify" onclick="showSubmitComplaintForm()" style="margin-left:auto;"><i class="fas fa-plus"></i> File New Complaint</button>
        </div>
    `;

    // Filter chips
    const filterDefs = [
        { key: 'all',                            label: 'All',                  icon: 'fa-list' },
        { key: 'pending_review',                 label: 'Pending Review',       icon: 'fa-clock' },
        { key: 'pending_captain_action',         label: 'Awaiting Captain',     icon: 'fa-hourglass-half' },
        { key: 'summoned',                       label: 'Summoned',             icon: 'fa-envelope-open-text' },
        { key: 'for_mediation',                  label: 'For Mediation',        icon: 'fa-gavel' },
        { key: 'mediation_scheduled',            label: 'Mediation Scheduled',  icon: 'fa-calendar-check' },
        { key: 'pangkat_constitution_scheduled', label: 'Pangkat Scheduled',    icon: 'fa-users-cog' },
        { key: 'pangkat_constituted',            label: 'Pangkat Constituted',  icon: 'fa-users' },
        { key: 'pangkat_scheduled',              label: 'Conciliation Set',     icon: 'fa-calendar-check' },
        { key: 'settled',                        label: 'Settled',              icon: 'fa-handshake' },
        { key: 'failed_mediation',               label: 'Mediation Failed',     icon: 'fa-times-circle' },
        { key: 'failed_conciliation_final',      label: 'Conciliation Failed',  icon: 'fa-times-circle' },
        { key: 'certificate_issued',             label: 'Certificate Issued',   icon: 'fa-certificate' },
        { key: 'released',                       label: 'Released',             icon: 'fa-check-double' },
        { key: 'referred_dispatched',            label: 'Referred',             icon: 'fa-paper-plane' },
        { key: 'pending_captain_review',         label: 'Captain Review',       icon: 'fa-user-tie' },
        { key: 'escalated',                      label: 'Escalated',            icon: 'fa-exclamation-triangle' },
        { key: 'dismissed',                      label: 'Dismissed',            icon: 'fa-ban' },
    ];

    const visibleFilters = filterDefs.filter(f => f.key === 'all' || (statusCounts[f.key] && statusCounts[f.key] > 0));

    const filterChipsHtml = `
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;padding:12px 14px;background:#f8faf8;border-radius:12px;border:1px solid #e2efe8;">
            <div style="display:flex;align-items:center;gap:6px;color:#666;font-size:0.78rem;font-weight:700;margin-right:6px;">
                <i class="fas fa-filter"></i> Filter:
            </div>
            ${visibleFilters.map(f => {
                const isActive = currentStatusFilter === f.key;
                const cnt = statusCounts[f.key] || 0;
                return `<button type="button" onclick="setStatusFilter('${f.key}')"
                               style="padding:6px 12px;border-radius:20px;border:1.5px solid ${isActive ? '#2E7D32' : '#e2efe8'};
                                      background:${isActive ? '#2E7D32' : '#fff'};
                                      color:${isActive ? '#fff' : '#555'};
                                      font-size:0.75rem;font-weight:700;cursor:pointer;
                                      display:inline-flex;align-items:center;gap:6px;transition:all .15s;">
                    <i class="fas ${f.icon}"></i> ${f.label}
                    <span style="background:${isActive ? 'rgba(255,255,255,0.3)' : '#f0f0f0'};padding:1px 7px;border-radius:10px;font-size:0.68rem;">${cnt}</span>
                </button>`;
            }).join('')}
        </div>
    `;

    // Filtered list
    const filteredList = currentStatusFilter === 'all'
        ? currentList
        : currentList.filter(c => c.status === currentStatusFilter);

    let bodyHtml = '';
    if (!filteredList.length) {
        bodyHtml = `
            <div class="empty-state">
                <i class="fas fa-folder-open fa-3x"></i>
                <p>${currentStatusFilter === 'all'
                    ? (currentComplaintTab === 'complainant' ? "You haven't filed any complaints yet." : "No complaints have named you as respondent yet.")
                    : `No complaints with status "${currentStatusFilter.replace(/_/g,' ')}".`}</p>
                ${currentStatusFilter !== 'all' ? `<button type="button" class="btn-verify" onclick="setStatusFilter('all')" style="margin-top:12px;"><i class="fas fa-list"></i> Show All</button>` : ''}
            </div>`;
    } else {
        // ── SPLIT INTO UPCOMING vs OTHERS ──
        const { upcoming, others } = splitByUpcomingHearing(filteredList);

        const gridStyle = `display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;align-items:start;`;

        // Section header helper
        const sectionHeader = (icon, label, count, color = '#2E7D32') => `
            <div style="display:flex;align-items:center;gap:10px;margin:0 0 14px;padding:10px 14px;
                        background:${color}0D;border-left:4px solid ${color};border-radius:10px;">
                <i class="fas ${icon}" style="color:${color};font-size:1rem;"></i>
                <div style="font-weight:800;color:${color};font-size:0.9rem;letter-spacing:0.3px;text-transform:uppercase;">
                    ${label}
                </div>
                <span style="background:${color};color:#fff;padding:2px 10px;border-radius:12px;font-size:0.7rem;font-weight:800;">${count}</span>
            </div>`;

        // Divider between sections
        const divider = `
            <div style="display:flex;align-items:center;gap:12px;margin:28px 0 20px;">
                <div style="flex:1;height:1px;background:linear-gradient(to right, transparent, #cfd8dc, transparent);"></div>
                <div style="display:inline-flex;align-items:center;gap:8px;padding:6px 16px;border-radius:20px;
                            background:#f8faf8;border:1px solid #e2efe8;color:#666;font-size:0.72rem;font-weight:800;
                            letter-spacing:0.4px;text-transform:uppercase;">
                    <i class="fas fa-ellipsis-h"></i> Other Complaints
                </div>
                <div style="flex:1;height:1px;background:linear-gradient(to right, transparent, #cfd8dc, transparent);"></div>
            </div>`;

        let html = '';

        // ── 1. UPCOMING HEARINGS SECTION (only if any) ──
        if (upcoming.length) {
            html += sectionHeader('fa-calendar-check', 'Upcoming Hearings', upcoming.length, '#6a1b9a');
            html += `<div style="${gridStyle}">
                ${upcoming.map(c => renderComplaintCard(c, currentComplaintTab)).join('')}
            </div>`;
        }

        // ── 2. DIVIDER + OTHERS SECTION (only if any) ──
        if (others.length) {
            if (upcoming.length) html += divider;
            if (!upcoming.length) {
                // When there are no upcoming hearings, only show "All Complaints" header
                html += sectionHeader('fa-folder-open', 'All Complaints', others.length, '#2E7D32');
            }
            html += `<div style="${gridStyle}">
                ${others.map(c => renderComplaintCard(c, currentComplaintTab)).join('')}
            </div>`;
        }

        bodyHtml = html;
    }

    container.innerHTML = tabsHtml + filterChipsHtml + bodyHtml;
    startLiveCountdowns();
}



/**
 * Split the list into:
 *   • upcoming — complaints with a hearing scheduled in the FUTURE
 *   • others   — everything else
 *
 * Upcoming is sorted soonest-first by (hearing_date, hearing_time).
 * Others keep their incoming order (already sorted by created_at DESC from the API).
 */
function splitByUpcomingHearing(list) {
    const now = Date.now();

    const upcoming = [];
    const others   = [];

    list.forEach(c => {
        if (!c.hearing_date) { others.push(c); return; }

        const dtStr = c.hearing_date + (c.hearing_time ? ' ' + c.hearing_time : ' 00:00:00');
        const ts = new Date(dtStr.replace(' ', 'T')).getTime();

        if (isNaN(ts) || ts <= now) {
            others.push(c);
        } else {
            upcoming.push({ ...c, __hearingTs: ts });
        }
    });

    // Sort upcoming from SOONEST to LATEST
    upcoming.sort((a, b) => a.__hearingTs - b.__hearingTs);

    return { upcoming, others };
}

function switchComplaintTab(tab) {
    currentComplaintTab = tab;
    currentStatusFilter = 'all';
    renderComplaintsWithTabs();
}

function setStatusFilter(key) {
    currentStatusFilter = key;
    renderComplaintsWithTabs();
}

function getStatusBadgeInfo(s) {
    const map = {
        'pending_review':                 { label: 'Pending Review',       color: '#f57c00', bg: '#fff8e1', icon: 'fa-clock' },
        'pending_captain_action':         { label: 'Awaiting Captain',     color: '#f57c00', bg: '#fff8e1', icon: 'fa-hourglass-half' },
        'summoned':                       { label: 'Summoned',             color: '#1565c0', bg: '#e3f2fd', icon: 'fa-envelope-open-text' },
        'for_mediation':                  { label: 'For Mediation',        color: '#6a1b9a', bg: '#f3e5f5', icon: 'fa-gavel' },
        'mediation_scheduled':            { label: 'Mediation Scheduled',  color: '#6a1b9a', bg: '#f3e5f5', icon: 'fa-calendar-check' },
        'settled':                        { label: 'Settled',              color: '#1b5e20', bg: '#e8f5e9', icon: 'fa-handshake' },
        'failed_mediation':               { label: 'Mediation Failed',     color: '#b71c1c', bg: '#ffebee', icon: 'fa-times-circle' },
        'failed_conciliation_final':      { label: 'Conciliation Failed',  color: '#b71c1c', bg: '#ffebee', icon: 'fa-times-circle' },
        'escalated':                      { label: 'Escalated',            color: '#c62828', bg: '#ffebee', icon: 'fa-exclamation-triangle' },
        'dismissed':                      { label: 'Dismissed',            color: '#555',    bg: '#e0e0e0', icon: 'fa-ban' },
        'pangkat_constitution_scheduled': { label: 'Pangkat Scheduled',    color: '#4a148c', bg: '#f3e5f5', icon: 'fa-users-cog' },
        'pangkat_constituted':            { label: 'Pangkat Constituted',  color: '#4a148c', bg: '#f3e5f5', icon: 'fa-users' },
        'pangkat_scheduled':              { label: 'Conciliation Set',     color: '#4a148c', bg: '#f3e5f5', icon: 'fa-calendar-check' },
        'certificate_issued':             { label: 'Certificate Issued',   color: '#1b5e20', bg: '#e8f5e9', icon: 'fa-certificate' },
        'released':                       { label: 'Released',             color: '#1b5e20', bg: '#e8f5e9', icon: 'fa-check-double' },
        'referred_dispatched':            { label: 'Referred',             color: '#8e24aa', bg: '#f3e5f5', icon: 'fa-paper-plane' },
        'pending_captain_review':         { label: 'Captain Review',       color: '#f57c00', bg: '#fff8e1', icon: 'fa-user-tie' },
    };
    return map[s] || { label: s.replace(/_/g, ' '), color: '#666', bg: '#f0f0f0', icon: 'fa-info-circle' };
}
function renderComplaintCard(c, roleTab) {
    const st = getStatusBadgeInfo(c.status);
    const isRespondent = roleTab === 'respondent';
    const isAnonymous  = c.is_anonymous == 1;
    const roleStripeColor = isRespondent ? '#e65100' : '#2E7D32';
    const roleLabel       = isRespondent ? 'Respondent' : 'Complainant';
    const roleIcon        = isRespondent ? 'fa-user-friends' : 'fa-user';
    const filedDate = c.created_at ? new Date(c.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '—';
    const canCancel = !isRespondent && (c.status === 'pending_review' || c.status === 'summoned');

    const priorityColors = { urgent: '#c62828', high: '#e65100', medium: '#f9a825', low: '#43a047' };
    const priorityColor  = priorityColors[c.priority] || '#888';

    // ── Hearing preview block (UPCOMING / ACTIVE ONLY) ────────
    // Past hearings are hidden — no block is rendered.
    let hearingBlock = '';
    if (c.hearing_date) {
        const hDateTime = c.hearing_date + (c.hearing_time ? ' ' + c.hearing_time : ' 00:00:00');
        const hearingTs = new Date(hDateTime.replace(' ', 'T')).getTime();
        const isPast    = !isNaN(hearingTs) && hearingTs < Date.now();

        // ⬇️ Only render if hearing is in the FUTURE (upcoming/active)
        if (!isPast) {
            const niceDate = new Date(c.hearing_date).toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
            const niceTime = c.hearing_time ? formatTime12(c.hearing_time) : '';
            const loc      = c.hearing_location || 'Barangay Hall';
            const isToday  = c.hearing_date === new Date().toISOString().split('T')[0];

            // Determine hearing label from status
            let hearingLabel = 'Hearing Scheduled';
            let accentColor  = '#6a1b9a';
            if (c.status === 'for_mediation' || c.status === 'mediation_scheduled') {
                hearingLabel = 'Mediation Hearing';
                accentColor  = '#6a1b9a';
            } else if (c.status === 'pangkat_scheduled' || c.status === 'pangkat_constituted') {
                hearingLabel = 'Conciliation Hearing';
                accentColor  = '#00838f';
            } else if (c.status === 'pangkat_constitution_scheduled') {
                hearingLabel = 'Pangkat Constitution Meeting';
                accentColor  = '#4a148c';
            }

            hearingBlock = `
                <div style="margin-top:12px;padding:12px 14px;border-radius:10px;
                            background:linear-gradient(135deg, #f3e5f5 0%, #ede7f6 100%);
                            border-left:4px solid ${accentColor};">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
                        <div style="display:inline-flex;align-items:center;gap:6px;font-size:0.72rem;font-weight:800;text-transform:uppercase;letter-spacing:0.4px;
                                    color:${accentColor};">
                            <i class="fas fa-gavel"></i> ${hearingLabel}
                        </div>
                        ${isToday ? `<span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:10px;font-size:0.62rem;font-weight:800;background:#c62828;color:#fff;text-transform:uppercase;letter-spacing:0.3px;animation:pulseToday 1.5s infinite;"><i class="fas fa-bell"></i> Today</span>` : ''}
                    </div>
                    <div style="display:flex;flex-wrap:wrap;gap:10px;font-size:0.78rem;color:#333;">
                        <span style="display:inline-flex;align-items:center;gap:5px;">
                            <i class="fas fa-calendar" style="color:${accentColor};"></i>
                            ${escapeHtml(niceDate)}
                        </span>
                        ${niceTime ? `
                        <span style="display:inline-flex;align-items:center;gap:5px;">
                            <i class="fas fa-clock" style="color:${accentColor};"></i>
                            ${escapeHtml(niceTime)}
                        </span>` : ''}
                        <span style="display:inline-flex;align-items:center;gap:5px;">
                            <i class="fas fa-map-marker-alt" style="color:${accentColor};"></i>
                            ${escapeHtml(loc)}
                        </span>
                    </div>
                    <div style="margin-top:8px;padding-top:8px;border-top:1px dashed rgba(106,27,154,0.2);
                                display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <i class="fas fa-stopwatch" style="color:${accentColor};font-size:0.85rem;"></i>
                        <span style="font-size:0.68rem;font-weight:800;color:#666;text-transform:uppercase;letter-spacing:0.4px;">Starts in</span>
                        <span class="live-countdown" data-deadline="${escapeHtml(hDateTime)}"
                              style="font-family:'Courier New',monospace;font-weight:800;font-size:0.85rem;color:${accentColor};">
                            Calculating…
                        </span>
                    </div>
                </div>`;
        }
    }

    return `
        <div style="position:relative;background:#fff;border-radius:14px;overflow:hidden;
                    box-shadow:0 2px 10px rgba(0,0,0,0.06);border:1px solid #e2efe8;
                    cursor:pointer;transition:all 0.2s ease;display:flex;flex-direction:column;"
             onclick="viewComplaintDetails(${c.id})"
             onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 22px rgba(0,0,0,0.10)';"
             onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='0 2px 10px rgba(0,0,0,0.06)';">
            <div style="height:5px;background:${roleStripeColor};"></div>
            <div style="padding:14px 16px 10px;display:flex;justify-content:space-between;align-items:flex-start;gap:10px;">
                <div style="flex:1;min-width:0;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap;">
                        <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:12px;font-size:0.68rem;font-weight:800;text-transform:uppercase;letter-spacing:0.4px;background:${roleStripeColor}15;color:${roleStripeColor};">
                            <i class="fas ${roleIcon}"></i> ${roleLabel}
                        </span>
                        ${isAnonymous ? `<span style="display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:12px;font-size:0.68rem;font-weight:800;text-transform:uppercase;letter-spacing:0.4px;background:#fff8e1;color:#e65100;"><i class="fas fa-user-shield"></i> Anonymous</span>` : ''}
                    </div>
                    <div style="font-weight:800;color:#1a2b22;font-size:0.95rem;line-height:1.35;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">
                        ${escapeHtml(c.title || 'Untitled Complaint')}
                    </div>
                </div>
                <span style="display:inline-flex;align-items:center;gap:5px;padding:5px 11px;border-radius:20px;font-size:0.68rem;font-weight:800;text-transform:uppercase;letter-spacing:0.4px;background:${st.bg};color:${st.color};white-space:nowrap;flex-shrink:0;">
                    <i class="fas ${st.icon}"></i> ${st.label}
                </span>
            </div>
            <div style="padding:6px 16px 14px;flex:1;">
                <div style="display:flex;gap:10px;font-size:0.78rem;color:#666;margin-bottom:8px;flex-wrap:wrap;">
                    <span><i class="fas fa-hashtag" style="color:#999;"></i> ${escapeHtml(c.reference_number)}</span>
                    <span><i class="fas fa-calendar" style="color:#999;"></i> ${escapeHtml(filedDate)}</span>
                </div>
                <div style="font-size:0.82rem;color:#555;line-height:1.5;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">
                    ${escapeHtml((c.description || '').substring(0, 140))}${(c.description || '').length > 140 ? '…' : ''}
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
                    <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:8px;background:#f0f4ff;color:#3949ab;font-size:0.68rem;font-weight:700;">
                        <i class="fas fa-tag"></i> ${escapeHtml(c.complaint_subject || '—')}
                    </span>
                    <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:8px;background:${priorityColor}15;color:${priorityColor};font-size:0.68rem;font-weight:800;text-transform:uppercase;letter-spacing:0.3px;">
                        <i class="fas fa-flag"></i> ${(c.priority || 'medium').toUpperCase()}
                    </span>
                </div>

                ${hearingBlock}
            </div>
            <div style="padding:11px 16px;background:#f8faf8;border-top:1px solid #e2efe8;display:flex;gap:8px;align-items:center;">
                <button type="button" onclick="event.stopPropagation(); viewComplaintDetails(${c.id})"
                        style="flex:1;padding:9px 12px;border:none;border-radius:8px;background:${roleStripeColor};color:#fff;font-size:0.8rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:6px;">
                    <i class="fas fa-eye"></i> View Details
                </button>
                ${canCancel ? `<button type="button" onclick="event.stopPropagation(); cancelMyComplaint(${c.id}, '${escapeHtml(c.reference_number)}')"
                        style="padding:9px 12px;border:1.5px solid #ef9a9a;border-radius:8px;background:#fff;color:#c62828;font-size:0.8rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:6px;">
                    <i class="fas fa-times-circle"></i> Cancel
                </button>` : ''}
            </div>
        </div>
    `;
}


function formatTime12(timeStr) {
    if (!timeStr) return '';
    const parts = timeStr.split(':');
    if (parts.length < 2) return timeStr;
    let h = parseInt(parts[0], 10);
    const m = parts[1];
    const ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return `${h}:${m} ${ampm}`;
}

// Inject "Today" pulse animation once
(function injectHearingCardStyles() {
    if (document.getElementById('hearingCardStyles')) return;
    const s = document.createElement('style');
    s.id = 'hearingCardStyles';
    s.textContent = `
        @keyframes pulseToday {
            0%, 100% { box-shadow: 0 0 0 0 rgba(198,40,40,0.5); }
            50%      { box-shadow: 0 0 0 6px rgba(198,40,40,0); }
        }
    `;
    document.head.appendChild(s);
})();

async function viewComplaintDetails(id) {
    currentComplaintId = id;
    const container = document.getElementById('complaintContent');
    container.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading details...</p></div>';
    try {
        const resp = await fetch(`complaint_ajax.php?action=get_complaint_details&id=${id}`);
        const data = await resp.json();
        if (data.success) renderComplaintDetails(data);
        else container.innerHTML = `<div class="empty-state"><p>${escapeHtml(data.message || 'Error loading details.')}</p><button class="btn-verify" onclick="showMyComplaints()" style="margin-top:12px;"><i class="fas fa-arrow-left"></i> Back</button></div>`;
    } catch (err) {
        container.innerHTML = '<div class="empty-state"><p>Error loading details.</p></div>';
    }
}

// ============================================================
// SECTION 15B: COMPLAINT DETAILS — Documentable UI
// ============================================================
function renderComplaintDetails(data) {
    const c = data.complaint;
    const myRole = data.my_role || 'complainant';
    const isRespondent = myRole === 'respondent';

    const hearings = data.hearings || [];
    const complainants = data.complainants || [];
    const respondents = data.respondents || [];
    const witnesses = data.witnesses || [];
    const incidents = data.incidents || [];
    const evidence = data.evidence || [];
    const kpForms = data.kp_forms || [];

    const st = getStatusBadgeInfo(c.status);
    const roleStripeColor = isRespondent ? '#e65100' : '#2E7D32';

    const filedDateNice = c.created_at ? new Date(c.created_at).toLocaleString('en-US', { dateStyle: 'long', timeStyle: 'short' }) : '—';

    const roleBanner = isRespondent ? `
        <div style="background:linear-gradient(135deg,#fff3e0,#ffe0b2);border-left:4px solid #e65100;border-radius:10px;padding:12px 16px;margin-bottom:16px;display:flex;gap:12px;align-items:flex-start;">
            <i class="fas fa-user-friends" style="color:#e65100;font-size:1.4rem;flex-shrink:0;"></i>
            <div style="font-size:0.85rem;color:#5d4037;line-height:1.5;">
                <strong style="display:block;margin-bottom:4px;color:#e65100;">You are the Respondent in this case</strong>
                You will see notices and updates issued by the Barangay. Some internal details are not shown here.
            </div>
        </div>` : '';

    const anonBanner = c.is_anonymous == 1 ? `
        <div style="background:linear-gradient(135deg,#fff8e1,#ffecb3);border-left:4px solid #ef6c00;border-radius:10px;padding:12px 16px;margin-bottom:16px;display:flex;gap:12px;align-items:flex-start;">
            <i class="fas fa-user-shield" style="color:#e65100;font-size:1.5rem;flex-shrink:0;"></i>
            <div style="font-size:0.82rem;color:#5d4037;line-height:1.5;">
                <strong style="display:block;margin-bottom:4px;color:#e65100;">Whistleblower Protection Active</strong>
                This complaint was filed anonymously.
            </div>
        </div>` : '';

    // ── Documentable section helper ──
    const sectionHeader = (num, icon, title) => `
        <div style="display:flex;align-items:center;gap:12px;font-size:0.95rem;font-weight:800;color:#1a472a;text-transform:uppercase;letter-spacing:0.3px;margin-bottom:14px;padding-bottom:10px;border-bottom:2px solid #e2efe8;">
            <span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;background:#2E7D32;color:#fff;border-radius:50%;font-size:0.8rem;font-weight:700;flex-shrink:0;">${num}</span>
            <i class="fas ${icon}"></i> ${title}
        </div>`;

    const docBlock = (num, icon, title, bodyHtml) => `
        <div style="background:#fff;border-radius:12px;padding:18px 22px;border:1px solid #e2efe8;margin-bottom:16px;">
            ${sectionHeader(num, icon, title)}
            ${bodyHtml}
        </div>`;

    // Progress bar for complainant only
    let progressHtml = '';
    if (!isRespondent) {
        const order = ['pending_review','pending_captain_action','summoned','for_mediation','mediation_scheduled','settled'];
        const currentIdx = order.indexOf(c.status);
        progressHtml = `
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin:14px 0 20px;">
                ${[
                    { key: 'pending_review', label: '1. Filed' },
                    { key: 'pending_captain_action', label: '2. Reviewed' },
                    { key: 'summoned', label: '3. Summoned' },
                    { key: 'for_mediation', label: '4. Mediation' },
                    { key: 'mediation_scheduled', label: '5. Conciliation' },
                    { key: 'settled', label: '6. Settled' }
                ].map(step => {
                    const stepIdx = order.indexOf(step.key);
                    const isDone = stepIdx < currentIdx || c.status === 'settled';
                    const isCurrent = step.key === c.status;
                    const bg = isDone ? '#2E7D32' : (isCurrent ? '#1976d2' : '#e0e0e0');
                    const fg = (isDone || isCurrent) ? '#fff' : '#888';
                    return `<div style="background:${bg};color:${fg};padding:6px 12px;border-radius:20px;font-size:0.75rem;font-weight:600;">${step.label}</div>`;
                }).join('')}
            </div>`;
    }

    // ── Section 1: Complaint Info ──
    const section1 = docBlock(1, 'fa-info-circle', 'Complaint Information', `
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px 20px;">
            <div><div style="font-size:0.7rem;text-transform:uppercase;color:#999;margin-bottom:3px;letter-spacing:0.4px;font-weight:600;">Reference</div><div style="font-size:0.9rem;color:#1a2b22;font-weight:700;font-family:monospace;">${escapeHtml(c.reference_number)}</div></div>
            <div><div style="font-size:0.7rem;text-transform:uppercase;color:#999;margin-bottom:3px;letter-spacing:0.4px;font-weight:600;">Filed</div><div style="font-size:0.9rem;color:#1a2b22;font-weight:600;">${escapeHtml(filedDateNice)}</div></div>
            <div><div style="font-size:0.7rem;text-transform:uppercase;color:#999;margin-bottom:3px;letter-spacing:0.4px;font-weight:600;">Type</div><div style="font-size:0.9rem;color:#1a2b22;font-weight:600;">${escapeHtml(c.complaint_subject || '—')}</div></div>
            <div><div style="font-size:0.7rem;text-transform:uppercase;color:#999;margin-bottom:3px;letter-spacing:0.4px;font-weight:600;">Priority</div><div style="font-size:0.9rem;color:#1a2b22;font-weight:600;">${(c.priority || 'medium').toUpperCase()}</div></div>
        </div>
        <div style="margin-top:14px;">
            <div style="font-size:0.7rem;text-transform:uppercase;color:#999;margin-bottom:5px;letter-spacing:0.4px;font-weight:600;">Description</div>
            <p style="color:#1a2b22;font-size:0.92rem;line-height:1.6;margin:0;white-space:pre-wrap;">${escapeHtml(c.description || '')}</p>
        </div>
        ${c.additional_notes ? `
            <div style="margin-top:14px;">
                <div style="font-size:0.7rem;text-transform:uppercase;color:#999;margin-bottom:5px;letter-spacing:0.4px;font-weight:600;">Additional Notes</div>
                <p style="color:#1a2b22;font-size:0.92rem;line-height:1.6;margin:0;white-space:pre-wrap;">${escapeHtml(c.additional_notes)}</p>
            </div>` : ''}
    `);

    // ── Section 2: Parties ──
    const partyCard = (p, idx, type) => {
        const roleColor = type === 'complainant' ? '#2E7D32' : '#e65100';
        const roleBg    = type === 'complainant' ? '#e8f5e9' : '#fff3e0';
        return `
            <div style="background:#fafbfa;border-left:4px solid ${roleColor};border-radius:10px;padding:12px 16px;">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                    <div style="width:32px;height:32px;border-radius:50%;background:${roleBg};color:${roleColor};display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.8rem;">${idx+1}</div>
                    <div style="font-weight:700;color:#1a2b22;font-size:0.9rem;">${escapeHtml(p.full_name)}</div>
                </div>
                <div style="font-size:0.78rem;color:#666;display:flex;flex-wrap:wrap;gap:12px;padding-left:42px;">
                    ${p.address ? `<span><i class="fas fa-map-marker-alt"></i> ${escapeHtml(p.address)}</span>` : ''}
                    ${p.contact_number ? `<span><i class="fas fa-phone"></i> ${escapeHtml(p.contact_number)}</span>` : ''}
                    ${p.relationship ? `<span><i class="fas fa-handshake"></i> ${escapeHtml(p.relationship)}</span>` : ''}
                </div>
            </div>`;
    };

    const section2 = docBlock(2, 'fa-users', `Parties Involved (${complainants.length + respondents.length}${witnesses.length ? ` + ${witnesses.length} witnesses` : ''})`, `
        ${complainants.length ? `<div style="font-size:0.75rem;font-weight:800;color:#2E7D32;text-transform:uppercase;letter-spacing:0.4px;margin-bottom:8px;">Complainants</div>
            <div style="display:grid;gap:8px;margin-bottom:16px;">${complainants.map((p,i) => partyCard(p,i,'complainant')).join('')}</div>` : ''}
        ${respondents.length ? `<div style="font-size:0.75rem;font-weight:800;color:#e65100;text-transform:uppercase;letter-spacing:0.4px;margin-bottom:8px;">Respondents</div>
            <div style="display:grid;gap:8px;margin-bottom:16px;">${respondents.map((p,i) => partyCard(p,i,'respondent')).join('')}</div>` : ''}
        ${witnesses.length ? `<div style="font-size:0.75rem;font-weight:800;color:#1565c0;text-transform:uppercase;letter-spacing:0.4px;margin-bottom:8px;">Witnesses</div>
            <div style="display:grid;gap:8px;">${witnesses.map((p,i) => `
                <div style="background:#fafbfa;border-left:4px solid #1565c0;border-radius:10px;padding:12px 16px;">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                        <div style="width:32px;height:32px;border-radius:50%;background:#e3f2fd;color:#1565c0;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.8rem;">${i+1}</div>
                        <div style="font-weight:700;color:#1a2b22;font-size:0.9rem;">${escapeHtml(p.full_name)}</div>
                    </div>
                    <div style="font-size:0.78rem;color:#666;display:flex;flex-wrap:wrap;gap:12px;padding-left:42px;">
                        ${p.address ? `<span><i class="fas fa-map-marker-alt"></i> ${escapeHtml(p.address)}</span>` : ''}
                        ${p.contact_number ? `<span><i class="fas fa-phone"></i> ${escapeHtml(p.contact_number)}</span>` : ''}
                    </div>
                </div>`).join('')}</div>` : ''}
    `);

    // ── Section 3: Incidents ──
    const section3 = incidents.length ? docBlock(3, 'fa-calendar-alt', `Incidents (${incidents.length})`, `
        <div style="display:grid;gap:12px;">
            ${incidents.map((inc, i) => {
                const ev = evidence[i] || [];
                return `
                    <div style="background:#fafbfa;border-left:4px solid #1976d2;border-radius:10px;padding:14px 16px;">
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                            <span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;background:#1976d2;color:#fff;border-radius:8px;font-weight:700;font-size:0.8rem;">${i+1}</span>
                            <div style="font-weight:800;color:#1a2b22;font-size:0.9rem;">Incident ${i+1}</div>
                        </div>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;font-size:0.82rem;color:#555;margin-bottom:10px;">
                            ${inc.incident_date ? `<div><i class="fas fa-calendar" style="color:#1976d2;"></i> ${new Date(inc.incident_date).toLocaleDateString('en-US', {dateStyle:'long'})}</div>` : ''}
                            ${inc.incident_time ? `<div><i class="fas fa-clock" style="color:#1976d2;"></i> ${escapeHtml(inc.incident_time)}</div>` : ''}
                            ${inc.location ? `<div><i class="fas fa-map-marker-alt" style="color:#1976d2;"></i> ${escapeHtml(inc.location)}</div>` : ''}
                        </div>
                        <p style="font-size:0.85rem;color:#1a2b22;line-height:1.6;margin:0;white-space:pre-wrap;">${escapeHtml(inc.description || '')}</p>
                        ${ev.length ? `<div style="margin-top:10px;padding-top:10px;border-top:1px dashed #cfd8dc;">
                            <div style="font-size:0.72rem;font-weight:800;color:#666;text-transform:uppercase;letter-spacing:0.4px;margin-bottom:6px;"><i class="fas fa-paperclip"></i> Evidence (${ev.length})</div>
                            <div style="display:flex;flex-wrap:wrap;gap:6px;">
                                ${ev.map(e => `<a href="../../${escapeHtml(e.file_path)}" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:5px;padding:5px 10px;background:#e3f2fd;color:#1565c0;border-radius:6px;font-size:0.72rem;font-weight:600;text-decoration:none;"><i class="fas fa-file"></i> ${escapeHtml(e.file_name)}</a>`).join('')}
                            </div>
                        </div>` : ''}
                    </div>`;
            }).join('')}
        </div>
    `) : '';

    // ── Section 4: KP Forms ──
    const section4 = kpForms.length ? docBlock(4, 'fa-file-signature', `Documents Issued to You (${kpForms.length})`, `
        <div style="display:grid;gap:10px;">
            ${kpForms.map(f => `
                <div style="background:#fafbfa;border-left:4px solid #1565c0;border-radius:10px;padding:12px 16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <div style="width:44px;height:44px;border-radius:10px;background:#e3f2fd;display:flex;align-items:center;justify-content:center;color:#1565c0;font-weight:800;font-size:0.9rem;flex-shrink:0;">
                        #${f.form_no}
                    </div>
                    <div style="flex:1;min-width:180px;">
                        <div style="font-weight:700;color:#1a2b22;font-size:0.9rem;">${escapeHtml(f.form_name)}</div>
                        <div style="font-size:0.78rem;color:#555;margin-top:2px;">${escapeHtml(f.event || '')}</div>
                        ${f.issued_at ? `<div style="font-size:0.7rem;color:#888;margin-top:4px;"><i class="fas fa-clock"></i> Issued ${new Date(f.issued_at.replace(' ','T')).toLocaleDateString('en-US',{dateStyle:'medium'})}</div>` : ''}
                    </div>
                    ${f.pdf_path ? `<a href="../../${escapeHtml(f.pdf_path)}" target="_blank" rel="noopener" style="padding:8px 14px;background:#1565c0;color:#fff;text-decoration:none;border-radius:8px;font-size:0.78rem;font-weight:700;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-file-pdf"></i> Open PDF</a>` : ''}
                </div>
            `).join('')}
        </div>
    `) : '';

    // ── Section 5: Hearings with LIVE TIMER ──
    const outcomeMap = { settled: 'Settled', no_settlement: 'No Settlement', cancelled: 'Cancelled' };
    const section5 = hearings.length ? docBlock(5, 'fa-gavel', `Hearing Schedule (${hearings.length})`, `
        <div style="display:grid;gap:12px;">
            ${hearings.map(h => {
                const typeLabel = h.session_type === 'mediation' ? 'Mediation' : (h.session_type === 'conciliation' ? 'Conciliation' : 'Hearing');
                const outcomeLabel = h.outcome ? (outcomeMap[h.outcome] || h.outcome.replace(/_/g,' ')) : null;
                const hearingDateTime = (h.hearing_date && h.hearing_time) ? `${h.hearing_date} ${h.hearing_time}` : (h.hearing_date ? `${h.hearing_date} 00:00:00` : null);
                const isCancelled = h.status === 'cancelled';
                const isCompleted = h.status === 'completed';
                const isScheduled = h.status === 'scheduled';

                return `
                    <div style="background:#f0f4ff;border-left:4px solid ${isCancelled ? '#9e9e9e' : (isCompleted ? '#43a047' : '#3949ab')};border-radius:10px;padding:14px 16px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px;">
                            <div style="font-weight:800;color:#1a2b22;font-size:0.92rem;display:flex;align-items:center;gap:8px;">
                                <i class="fas fa-calendar-check" style="color:${isCancelled ? '#9e9e9e' : (isCompleted ? '#43a047' : '#3949ab')};"></i>
                                ${typeLabel} Hearing #${h.session_number || 1}
                            </div>
                            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                                ${isScheduled ? `<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:12px;font-size:0.66rem;font-weight:800;text-transform:uppercase;background:#e8eaf6;color:#3949ab;"><i class="fas fa-hourglass-half"></i> Scheduled</span>` : ''}
                                ${isCompleted ? `<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:12px;font-size:0.66rem;font-weight:800;text-transform:uppercase;background:#e8f5e9;color:#1b5e20;"><i class="fas fa-check-circle"></i> Completed</span>` : ''}
                                ${isCancelled ? `<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:12px;font-size:0.66rem;font-weight:800;text-transform:uppercase;background:#e0e0e0;color:#555;"><i class="fas fa-ban"></i> Cancelled</span>` : ''}
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;font-size:0.82rem;color:#555;">
                            <div><i class="fas fa-calendar" style="color:#3949ab;"></i> ${h.hearing_date ? new Date(h.hearing_date).toLocaleDateString('en-US',{dateStyle:'long'}) : '—'}</div>
                            <div><i class="fas fa-clock" style="color:#3949ab;"></i> ${escapeHtml(h.hearing_time || '—')}</div>
                            <div><i class="fas fa-map-marker-alt" style="color:#3949ab;"></i> ${escapeHtml(h.location || 'Barangay Hall')}</div>
                        </div>
                        ${isScheduled && hearingDateTime ? `
                            <div style="margin-top:10px;padding:10px 14px;background:#fff;border:1px solid #c5cae9;border-radius:8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;" class="live-countdown-wrap">
                                <i class="fas fa-stopwatch" style="color:#3949ab;font-size:1.1rem;"></i>
                                <div style="font-size:0.75rem;font-weight:700;color:#666;text-transform:uppercase;letter-spacing:0.4px;">Time Remaining:</div>
                                <div class="live-countdown" data-deadline="${escapeHtml(hearingDateTime)}" style="font-family:'Courier New',monospace;font-weight:800;font-size:0.95rem;color:#3949ab;">Calculating…</div>
                            </div>` : ''}
                        ${outcomeLabel ? `<div style="font-size:0.78rem;color:#3949ab;font-weight:800;margin-top:8px;text-transform:uppercase;letter-spacing:0.4px;"><i class="fas fa-info-circle"></i> Outcome: ${escapeHtml(outcomeLabel)}</div>` : ''}
                    </div>`;
            }).join('')}
        </div>
    `) : '';

    // ── Section 6: Add Note (complainant only) ──
    const section6 = !isRespondent ? docBlock(6, 'fa-comment', 'Add a Note', `
        <textarea id="complaintNote" class="form-control" rows="3" placeholder="Add a note..."></textarea>
        <button class="btn-verify" onclick="addComplaintNote(${c.id})" style="margin-top:10px;"><i class="fas fa-paper-plane"></i> Add Note</button>
    `) : `
        <div style="background:#f8faf8;border-radius:12px;padding:18px 22px;border:1px dashed #e2efe8;text-align:center;margin-bottom:16px;">
            <i class="fas fa-lock" style="color:#999;font-size:1.2rem;margin-bottom:6px;display:block;"></i>
            <p style="font-size:0.8rem;color:#888;margin:0;">Notes are not available while you are a respondent in this case.</p>
        </div>`;

    const container = document.getElementById('complaintContent');
    container.innerHTML = `
        <button class="btn-back" onclick="showMyComplaints()" style="margin-bottom:20px;"><i class="fas fa-arrow-left"></i> Back to Complaints</button>

        <div style="position:relative;overflow:hidden;background:#f8faf8;border-radius:16px;padding:0 0 22px 0;">
            <div style="position:absolute;top:0;left:0;right:0;height:5px;background:${roleStripeColor};"></div>

            <!-- Header -->
            <div style="padding:20px 24px 4px;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;">
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:0.72rem;color:#666;text-transform:uppercase;letter-spacing:0.6px;font-weight:800;margin-bottom:6px;">Complaint Record</div>
                        <h2 style="font-size:1.2rem;font-weight:800;color:#1a2b22;margin:0 0 8px;line-height:1.35;">${escapeHtml(c.title)}</h2>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                            <span style="display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:20px;font-size:0.72rem;font-weight:800;text-transform:uppercase;letter-spacing:0.4px;background:${st.bg};color:${st.color};">
                                <i class="fas ${st.icon}"></i> ${st.label}
                            </span>
                            <span style="display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:20px;font-size:0.72rem;font-weight:800;text-transform:uppercase;letter-spacing:0.4px;background:${roleStripeColor}15;color:${roleStripeColor};">
                                <i class="fas ${isRespondent ? 'fa-user-friends' : 'fa-user'}"></i> ${isRespondent ? 'Respondent' : 'Complainant'}
                            </span>
                        </div>
                    </div>
                </div>
                ${progressHtml}
            </div>

            <!-- Banners -->
            <div style="padding:0 24px;">
                ${roleBanner}
                ${anonBanner}
            </div>

            <!-- Documentable sections -->
            <div style="padding:0 24px;">
                ${section1}
                ${section2}
                ${section3}
                ${section4}
                ${section5}
                ${section6}
            </div>
        </div>
    `;

    // Start live timer
    startLiveCountdowns();
}

function startLiveCountdowns() {
    if (__liveTimerInterval) clearInterval(__liveTimerInterval);
    const tick = () => document.querySelectorAll('.live-countdown[data-deadline]').forEach(renderCountdown);
    tick();
    __liveTimerInterval = setInterval(tick, 1000);
}

function renderCountdown(el) {
    const iso = el.getAttribute('data-deadline');
    if (!iso) { el.textContent = '—'; return; }
    const target = new Date(iso.replace(' ', 'T'));
    if (isNaN(target.getTime())) { el.textContent = 'Invalid date'; return; }
    const diff = target.getTime() - Date.now();

    if (diff <= 0) {
        el.innerHTML = '<span style="color:#c62828;"><i class="fas fa-play-circle"></i> Hearing in progress / past</span>';
        return;
    }
    const totalSec = Math.floor(diff / 1000);
    const d = Math.floor(totalSec / 86400);
    const h = Math.floor((totalSec % 86400) / 3600);
    const m = Math.floor((totalSec % 3600) / 60);
    const s = totalSec % 60;

    const pad = n => String(n).padStart(2, '0');
    let text = '';
    if (d > 0) text = `${d}d ${pad(h)}h ${pad(m)}m ${pad(s)}s`;
    else if (h > 0) text = `${pad(h)}h ${pad(m)}m ${pad(s)}s`;
    else text = `${pad(m)}m ${pad(s)}s`;

    let color = '#3949ab';
    if (d === 0 && h < 3) color = '#f57c00';
    if (d === 0 && h === 0 && m < 30) color = '#c62828';

    el.innerHTML = `<span style="color:${color};"><i class="fas fa-hourglass-half"></i> ${text}</span>`;
}

async function addComplaintNote(id) {
    const note = document.getElementById('complaintNote')?.value;
    if (!note?.trim()) { showWarningModal('Please enter a note.'); return; }
    const fd = new FormData();
    fd.append('action', 'add_complaint_note');
    fd.append('complaint_id', id);
    fd.append('note', note);
    try {
        const resp = await fetch('complaint_ajax.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) { showSuccessModal('Note added'); document.getElementById('complaintNote').value = ''; viewComplaintDetails(id); }
        else showErrorModal(data.message);
    } catch (err) { showErrorModal('Error adding note'); }
}

async function cancelMyComplaint(id, referenceNumber) {
    const confirm = await openAppModal({ type: 'warning', title: 'Cancel Complaint?', message: `Are you sure you want to withdraw complaint ${referenceNumber}? This cannot be undone.`, buttonText: 'Yes, Cancel Complaint' });
    if (!confirm) return;
    const fd = new FormData();
    fd.append('action', 'cancel_complaint');
    fd.append('complaint_id', id);
    try {
        const r = await fetch('complaint_ajax.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) { showSuccessModal('Complaint withdrawn'); showMyComplaints(); }
        else { showErrorModal(d.message || 'Could not cancel'); }
    } catch (e) { showErrorModal('Network error'); }
}

// ============================================================
// SECTION 16: FORM HELPERS
// ============================================================
function showSubmitComplaintForm() {
    if (!ensureComplaintContainer()) return;
    const r = window.resident || {};
    const rName = r.name || r.firstName + ' ' + r.lastName || '';
    complainantsList = [{ full_name: rName, address: r.address || '', contact_number: r.phone || '', email: r.email || '' }];
    respondentsList = [];
    witnessesList = [];
    incidentsList = [{ incident_date: '', incident_time: '', location: '', description: '', evidence_files: [] }];

    const container = document.getElementById('complaintContent');
    if (container) {
        container.innerHTML = getSubmitComplaintForm();
        attachIncidentEventListeners();
        attachPartyEventListeners();
        ensureFieldErrorStyles();
        attachFieldAutoClear();
        const descInput = document.getElementById('complaintDescription');
        if (descInput) { descInput.removeEventListener('input', onDescriptionChange); descInput.addEventListener('input', onDescriptionChange); }
        const subjectSelect = document.getElementById('complaintSubject');
        if (subjectSelect) {
            subjectSelect.addEventListener('mousedown', () => { userInteractedWithSubject = true; });
            subjectSelect.addEventListener('touchstart', () => { userInteractedWithSubject = true; });
            subjectSelect.addEventListener('keydown', () => { userInteractedWithSubject = true; });
        }
    }
}

function resetComplaintForm() {
    const form = document.getElementById('complaintForm');
    if (form) form.reset();
    const r = window.resident || {};
    const rName = r.name || r.firstName + ' ' + r.lastName || '';
    complainantsList = [{ full_name: rName, address: r.address || '', contact_number: r.phone || '', email: r.email || '' }];
    respondentsList = [];
    witnessesList = [];
    incidentsList = [{ incident_date: '', incident_time: '', location: '', description: '', evidence_files: [] }];
    ['complainantsContainer', 'respondentsContainer', 'witnessesContainer', 'incidentsContainer'].forEach(id => {
        const el = document.getElementById(id);
        if (id === 'complainantsContainer' && el) el.innerHTML = renderComplainantsList();
        else if (id === 'respondentsContainer' && el) el.innerHTML = renderRespondentsList();
        else if (id === 'witnessesContainer' && el) el.innerHTML = renderWitnessesList();
        else if (id === 'incidentsContainer' && el) el.innerHTML = renderIncidentsList();
    });
    attachPartyEventListeners();
    attachIncidentEventListeners();
    ensureFieldErrorStyles();
    attachFieldAutoClear();
    const subjectSelect = document.getElementById('complaintSubject');
    if (subjectSelect) subjectSelect.innerHTML = '<option value="" disabled selected>┅ I-type ang paglalarawan para makita ang mungkahi ┅</option>';
    clearTitleSuggestion();
    hideLawInfoPanel();
    const anon = document.getElementById('isAnonymous');
    if (anon) anon.checked = false;
    const anonGroup = document.getElementById('anonymityGroup');
    if (anonGroup) anonGroup.style.display = 'none';
    toggleAnonymityWarning();
}

function ensureComplaintContainer() {
    let body = document.getElementById('dashboardBody');
    if (!body) return false;
    let content = document.getElementById('complaintContent');
    if (!content) {
        body.innerHTML = `<div class="content-card"><div class="section-title"><i class="fas fa-gavel"></i> Complaint Management</div><div id="complaintContent"><div class="loading-spinner"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading...</p></div></div></div>`;
        content = document.getElementById('complaintContent');
    }
    return !!content;
}

function initComplaintModule() { if (ensureComplaintContainer()) showSubmitComplaintForm(); }
function escapeHtml(t) { if (!t) return ''; const d = document.createElement('div'); d.textContent = t; return d.innerHTML; }

function showLoading(m) {
    let ld = document.getElementById('loadingOverlay');
    if (!ld) { ld = document.createElement('div'); ld.id = 'loadingOverlay'; ld.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:10000;display:flex;justify-content:center;align-items:center;'; document.body.appendChild(ld); }
    ld.style.display = 'flex';
    ld.innerHTML = `<div style="background:white;padding:30px;border-radius:16px;text-align:center;"><i class="fas fa-spinner fa-spin" style="font-size:40px;color:#2E7D32;"></i><p>${m}</p></div>`;
}
function hideLoading() { const ld = document.getElementById('loadingOverlay'); if (ld) ld.style.display = 'none'; }

// ============================================================
// SECTION 17: MODAL HELPERS
// ============================================================
function ensureModalStyles() {
    if (document.getElementById('appModalStyle')) return;
    const style = document.createElement('style');
    style.id = 'appModalStyle';
    style.textContent = `
        .app-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.55); display: flex; align-items: center; justify-content: center; z-index: 10001; }
        .app-modal-box { background: #fff; border-radius: 16px; padding: 32px 28px 24px; width: 90%; max-width: 400px; text-align: center; }
        .app-modal-icon { width: 68px; height: 68px; margin: 0 auto 16px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 34px; }
        .app-modal-icon.success { background: #e8f5e9; color: #2E7D32; }
        .app-modal-icon.error   { background: #ffebee; color: #c62828; }
        .app-modal-icon.warning { background: #fff8e1; color: #ef6c00; }
        .app-modal-icon.info    { background: #e3f2fd; color: #1565c0; }
        .app-modal-title { font-size: 19px; font-weight: 700; margin-bottom: 8px; }
        .app-modal-message { font-size: 14px; color: #555; }
        .app-modal-ref { display: inline-block; margin-top: 12px; padding: 8px 16px; background: #f1f8e9; border: 1px dashed #a5d6a7; border-radius: 8px; font-family: monospace; font-weight: 700; color: #2E7D32; }
        .app-modal-btn { margin-top: 22px; width: 100%; padding: 12px 20px; border: none; border-radius: 10px; color: #fff; font-size: 15px; font-weight: 600; cursor: pointer; }
        .app-modal-btn.success { background: #2E7D32; }
        .app-modal-btn.error   { background: #c62828; }
        .app-modal-btn.warning { background: #ef6c00; }
        .app-modal-btn.info    { background: #1565c0; }
    `;
    document.head.appendChild(style);
}

function openAppModal({ type = 'info', title = '', message = '', reference = '', buttonText = 'OK' }) {
    ensureModalStyles();
    const existing = document.getElementById('appModalOverlay');
    if (existing) existing.remove();
    const iconMap = { success: 'fa-check', error: 'fa-times', warning: 'fa-exclamation-triangle', info: 'fa-info-circle' };
    const iconClass = iconMap[type] || iconMap.info;
    const overlay = document.createElement('div');
    overlay.id = 'appModalOverlay';
    overlay.className = 'app-modal-overlay';
    overlay.innerHTML = `
        <div class="app-modal-box">
            <div class="app-modal-icon ${type}"><i class="fas ${iconClass}"></i></div>
            <div class="app-modal-title">${escapeHtml(title)}</div>
            ${message ? `<div class="app-modal-message">${escapeHtml(message)}</div>` : ''}
            ${reference ? `<div class="app-modal-ref">${escapeHtml(reference)}</div>` : ''}
            <button type="button" class="app-modal-btn ${type}" id="appModalBtn">${escapeHtml(buttonText)}</button>
        </div>
    `;
    document.body.appendChild(overlay);
    return new Promise((resolve) => {
        const close = () => { overlay.remove(); resolve(true); };
        document.getElementById('appModalBtn').addEventListener('click', close);
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    });
}

function showSuccessModal(m) { return openAppModal({ type: 'success', title: 'Success!', message: m, buttonText: 'OK' }); }
function showErrorModal(m)   { return openAppModal({ type: 'error',   title: 'Error!',   message: m, buttonText: 'OK' }); }
function showWarningModal(m) { return openAppModal({ type: 'warning', title: 'Warning',  message: m, buttonText: 'OK' }); }
function showComplaintSuccessModal(referenceNumber, onClose) {
    openAppModal({ type: 'success', title: 'Complaint Submitted!', message: 'Your complaint has been filed successfully. Please save your reference number:', reference: referenceNumber, buttonText: 'OK' }).then(() => { if (typeof onClose === 'function') onClose(); });
}

// ============================================================
// SECTION 18: FIELD-LEVEL VALIDATION
// ============================================================
function ensureFieldErrorStyles() {
    if (document.getElementById('fieldErrorStyle')) return;
    const style = document.createElement('style');
    style.id = 'fieldErrorStyle';
    style.textContent = `
        .field-error { border: 2px solid #e53935 !important; background-color: #fff5f5 !important; }
        .field-error-msg { display: block; margin-top: 6px; color: #e53935; font-size: 12px; font-weight: 500; }
        .party-card.field-error-group, .incident-card.field-error-group { border: 2px solid #e53935 !important; background-color: #fff5f5 !important; }
    `;
    document.head.appendChild(style);
}

function markFieldError(el, message) {
    if (!el) return;
    el.classList.add('field-error');
    const next = el.nextElementSibling;
    if (next && next.classList && next.classList.contains('field-error-msg')) { next.innerHTML = '<i class="fas fa-exclamation-circle"></i>' + message; return; }
    const msg = document.createElement('span');
    msg.className = 'field-error-msg';
    msg.innerHTML = '<i class="fas fa-exclamation-circle"></i>' + message;
    el.parentNode.insertBefore(msg, el.nextSibling);
}

function markGroupError(el, message) {
    if (!el) return;
    el.classList.add('field-error-group');
    let msg = el.querySelector(':scope > .field-error-msg');
    if (msg) { msg.innerHTML = '<i class="fas fa-exclamation-circle"></i>' + message; return; }
    msg = document.createElement('span');
    msg.className = 'field-error-msg';
    msg.style.margin = '10px 0 0';
    msg.innerHTML = '<i class="fas fa-exclamation-circle"></i>' + message;
    el.appendChild(msg);
}

function clearAllFieldErrors() {
    document.querySelectorAll('#complaintForm .field-error').forEach(el => el.classList.remove('field-error'));
    document.querySelectorAll('#complaintForm .field-error-group').forEach(el => el.classList.remove('field-error-group'));
    document.querySelectorAll('#complaintForm .field-error-msg').forEach(el => el.remove());
}

function attachFieldAutoClear() {
    const form = document.getElementById('complaintForm');
    if (!form) return;
    form.querySelectorAll('input, select, textarea').forEach(el => {
        el.removeEventListener('input', clearFieldErrorOnInput);
        el.removeEventListener('change', clearFieldErrorOnInput);
        el.addEventListener('input', clearFieldErrorOnInput);
        el.addEventListener('change', clearFieldErrorOnInput);
    });
}

function clearFieldErrorOnInput(e) {
    const el = e.target;
    el.classList.remove('field-error');
    const next = el.nextElementSibling;
    if (next && next.classList && next.classList.contains('field-error-msg')) next.remove();
    const card = el.closest('.party-card, .incident-card');
    if (card) {
        card.classList.remove('field-error-group');
        const cardMsg = card.querySelector(':scope > .field-error-msg');
        if (cardMsg) cardMsg.remove();
    }
}

// ============================================================
// GLOBAL EXPORTS
// ============================================================
window.initComplaintModule = initComplaintModule;
window.showSubmitComplaintForm = showSubmitComplaintForm;
window.showMyComplaints = showMyComplaints;
window.switchComplaintTab = switchComplaintTab;
window.setStatusFilter = setStatusFilter;
window.submitComplaint = submitComplaint;
window.viewComplaintDetails = viewComplaintDetails;
window.addComplaintNote = addComplaintNote;
window.cancelMyComplaint = cancelMyComplaint;
window.resetComplaintForm = resetComplaintForm;
window.addComplainant = addComplainant;
window.addRespondent = addRespondent;
window.addWitness = addWitness;
window.addIncident = addIncident;
window.removeIncident = removeIncident;
window.addIncidentEvidence = addIncidentEvidence;
window.removeIncidentEvidence = removeIncidentEvidence;
window.applyTitleSuggestion = applyTitleSuggestion;
window.toggleAnonymityWarning = toggleAnonymityWarning;
window.updateAnonymityVisibility = updateAnonymityVisibility;

console.log('✅ Katarungang Pambarangay Classifier loaded (Card View + Filters + Documentable UI + Live Timer)');
console.log('📚 Laws:', barangayLaws.length);
console.log('🎯 Victim-aware: minor, senior, woman, pwd, property, public');
console.log('🚨 Non-jurisdictional handling active');
console.log('🔒 Selective Anonymity: enabled');
console.log('⏱️ Live hearing countdown timers active');