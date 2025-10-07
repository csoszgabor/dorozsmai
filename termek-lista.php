<?php
require __DIR__ . '/inc/bootstrap.php';

/* --- Paraméterek --- */
$cat  = $_GET['cat']  ?? '';
$sub  = $_GET['sub']  ?? '';
$q    = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'az';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;

switch ($sort) {
  case 'za':  $orderSql = 'c.sort DESC, c.title DESC'; break;
  case 'new': $orderSql = 'datetime(c.created_at) DESC'; break;
  case 'old': $orderSql = 'datetime(c.created_at) ASC'; break;
  default:    $orderSql = 'c.sort ASC, c.title ASC';
}

/* -------- Normalizálók -------- */
function norm_space($s){
  $s = str_replace("\xC2\xA0", ' ', $s);
  return preg_replace('~\s+~', ' ', $s);
}
function norm_slug($s){ return strtolower(trim($s ?? '')); }
function norm_name($s){
  if ($s === null) return '';
  $s = norm_space(trim($s));
  if ($s === '') return '';
  $t = @iconv('UTF-8','ASCII//TRANSLIT',$s);
  if ($t === false) $t = $s;
  $t = strtolower($t);
  $t = preg_replace('~\s+~',' ', $t);
  return trim($t);
}
/* Üres-e a HTML (csak whitespace/nbsp/tagek)? */
function has_html($html){
  if ($html === null) return false;
  $t = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $t = str_replace(["\xC2\xA0",'&nbsp;'], ' ', $t);
  $t = trim(strip_tags($t));
  return $t !== '';
}

/* --- Bal oldali fa: főkategóriák + alkategóriák (duplikátum-szűréssel) --- */
$catsRaw = fetchAll($pdo, "SELECT id,name,slug,description,is_new FROM categories ORDER BY name, id");
$subsRaw = fetchAll($pdo, "SELECT id,category_id,name,slug FROM subcategories ORDER BY name, id");

/* Főkategóriák dedup: először slug, aztán név */
$cats         = [];
$catIdMap     = []; // régi_id -> megtartott_id
$seenCatSlug  = []; // norm_slug(slug) -> kept id
$seenCatName  = []; // norm_name(name) -> kept id

foreach ($catsRaw as $c) {
  $c['name'] = norm_space($c['name']);
  $slugKey   = norm_slug($c['slug']);
  $nameKey   = norm_name($c['name']);

  $keepId = null;
  if ($slugKey !== '' && isset($seenCatSlug[$slugKey])) {
    $keepId = $seenCatSlug[$slugKey];
  } elseif ($nameKey !== '' && isset($seenCatName[$nameKey])) {
    $keepId = $seenCatName[$nameKey];
  }

  if ($keepId !== null) {
    $catIdMap[$c['id']] = $keepId;
    continue;
  }

  $cats[] = $c;
  $catIdMap[$c['id']] = $c['id'];
  if ($slugKey !== '') $seenCatSlug[$slugKey] = $c['id'];
  if ($nameKey !== '') $seenCatName[$nameKey] = $c['id'];
}

/* Alkategóriák dedup + ID-map: (kept_cat, slug) / különben (kept_cat, name) */
$subs              = [];
$subIdMap          = []; // régi_sub_id -> megtartott_sub_id
$subKeyToKeptSlug  = []; // key -> kept id
$subKeyToKeptName  = []; // key -> kept id

foreach ($subsRaw as $s) {
  $keptCatId = $catIdMap[$s['category_id']] ?? $s['category_id'];
  $s['name'] = norm_space($s['name']);
  $slugKey   = norm_slug($s['slug']);
  $nameKey   = norm_name($s['name']);

  $keySlug = $keptCatId.'|'.$slugKey;
  $keyName = $keptCatId.'|'.$nameKey;

  if ($slugKey !== '' && isset($subKeyToKeptSlug[$keySlug])) {
    $subIdMap[$s['id']] = $subKeyToKeptSlug[$keySlug];
    continue;
  }
  if ($slugKey === '' && $nameKey !== '' && isset($subKeyToKeptName[$keyName])) {
    $subIdMap[$s['id']] = $subKeyToKeptName[$keyName];
    continue;
  }

  $s['category_id'] = $keptCatId;
  $subs[] = $s;
  $subIdMap[$s['id']] = $s['id'];
  if ($slugKey !== '') $subKeyToKeptSlug[$keySlug] = $s['id'];
  if ($nameKey !== '') $subKeyToKeptName[$keyName] = $s['id'];
}

/* Csoportosítás a fához */
$subsByCat = [];
foreach ($cats as $c) $subsByCat[$c['id']] = [];
foreach ($subs as $s) $subsByCat[$s['category_id']][] = $s;

/* --- Kereséshez: mely kategóriák/alkategóriák tartalmaznak találatot? --- */
/* Fontos: ugyanazokban a mezőkben keressünk, mint a jobb oldali listában! */
$hitCatIds   = [];
$hitSubsByCat= []; // cat_id => [sub_id=>true]  | '__ALL__' => true (ha sub_id NULL volt)
if ($q !== '') {
  $hits = fetchAll($pdo, "
    SELECT DISTINCT c.category_id AS category_id, c.subcategory_id AS subcategory_id
    FROM catalogs c
    JOIN categories     cat ON cat.id=c.category_id
    LEFT JOIN subcategories sub ON sub.id=c.subcategory_id
    WHERE (
      c.title LIKE :q OR c.short_text LIKE :q OR c.body_html LIKE :q OR c.slug LIKE :q
      OR cat.name LIKE :q OR cat.slug LIKE :q
      OR sub.name LIKE :q OR sub.slug LIKE :q
    )
  ", [':q'=>'%'.$q.'%']);
  foreach ($hits as $h) {
    $keptCat = $catIdMap[$h['category_id']] ?? (int)$h['category_id'];
    $hitCatIds[$keptCat] = true;
    if (!empty($h['subcategory_id'])) {
      $keptSub = $subIdMap[$h['subcategory_id']] ?? (int)$h['subcategory_id'];
      $hitSubsByCat[$keptCat][$keptSub] = true;
    } else {
      $hitSubsByCat[$keptCat]['__ALL__'] = true; // kategóriaszintű találat (sub_id NULL)
    }
  }
}

/* --- Jobb oldali lista szűrve + lapozással --- */
$where = ['1=1']; $p=[];
if ($cat !== '') { $where[]='cat.slug=:cat'; $p[':cat']=$cat; }
if ($sub !== '') { $where[]='sub.slug=:sub'; $p[':sub']=$sub; }
if ($q   !== '') {
  $where[] = '('
    .'c.title LIKE :q OR c.short_text LIKE :q OR c.body_html LIKE :q OR c.slug LIKE :q'
    .' OR cat.name LIKE :q OR cat.slug LIKE :q'
    .' OR sub.name LIKE :q OR sub.slug LIKE :q'
  .')';
  $p[':q']='%'.$q.'%';
}

$countSql = "
  SELECT COUNT(*)
  FROM catalogs c
  JOIN categories     cat ON cat.id=c.category_id
  LEFT JOIN subcategories sub ON sub.id=c.subcategory_id
  WHERE ".implode(' AND ',$where);
$st = $pdo->prepare($countSql);
$st->execute($p);
$total = (int)$st->fetchColumn();

$pages  = max(1, (int)ceil($total / $perPage));
$page   = min($page, $pages);
$offset = ($page - 1) * $perPage;

$sql = "
SELECT c.*, cat.name AS cat_name, cat.slug AS cat_slug, cat.description AS cat_desc,
       sub.name AS sub_name, sub.slug AS sub_slug
FROM catalogs c
JOIN categories     cat ON cat.id=c.category_id
LEFT JOIN subcategories sub ON sub.id=c.subcategory_id
WHERE ".implode(' AND ',$where)."
ORDER BY $orderSql
LIMIT $perPage OFFSET $offset";
$items = fetchAll($pdo, $sql, $p);

/* PDFs + képek */
$pdfsBy = $mediaBy = [];
if ($items){
  $ids = implode(',', array_map('intval', array_column($items,'id')));
  foreach (fetchAll($pdo,"SELECT * FROM catalog_pdfs  WHERE catalog_id IN ($ids) ORDER BY id") as $r)
    $pdfsBy[$r['catalog_id']][] = $r;
  foreach (fetchAll($pdo,"SELECT * FROM catalog_media WHERE catalog_id IN ($ids) ORDER BY id") as $r)
    $mediaBy[$r['catalog_id']][] = $r;
}

/* Aktív címek a fejlécben – a deduplikált listából */
$activeCat = null; $activeSub = null;
if ($cat) foreach($cats as $c) if ($c['slug']===$cat) $activeCat=$c;
if ($sub) foreach($subs as $s) if ($s['slug']===$sub) $activeSub=$s;

/* URL helper a szűrőkhöz */
function urlWith(array $merge){
  $q = array_merge($_GET, $merge);
  return '?'.http_build_query(array_filter($q, fn($v)=>$v!=='' && $v!==null));
}
?>
<!DOCTYPE html>
<html lang="hu"><!-- InstanceBegin template="/Templates/menu.dwt" codeOutsideHTMLIsLocked="false" -->
<head>
<script id="Cookiebot" src="https://consent.cookiebot.com/uc.js" data-cbid="79684881-447b-424d-9ced-915ae520c319" data-blockingmode="auto" type="text/javascript"></script>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<!-- InstanceBeginEditable name="doctitle" -->	
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="A kecskeméti Dorozs Hidraulika hidraulika henger, kézi pumpa, nyomatékra húzás, hidraulika szivattyú, légszivattyú, elektromos szivattyú, láb szivattyú, lapos henger, 700 báros hidraulika, nagy nyomatékú csavarozás, akkumulátoros nyomatékkulcs, csőkarima szerelő szerszámok forgalmazásával és szervizelésével foglalkozik!">
<meta name="author" content="Ipari Fotó">
<title>Dorozs Hidraulika</title>
<!-- InstanceEndEditable -->	
<link rel="stylesheet" type="text/css" href="rs-plugin/css/settings.css" media="screen" />
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="css/font-awesome.min.css" rel="stylesheet" type="text/css">
<link href="css/ionicons.min.css" rel="stylesheet">
<link href="css/main.css" rel="stylesheet">
<link href="css/style.css" rel="stylesheet">
<link href="css/responsive.css" rel="stylesheet">
<link href="css/simplelightbox.css" rel="stylesheet">
<link href='https://fonts.googleapis.com/css?family=Oswald:400,300,700' rel='stylesheet' type='text/css'>
<link href='https://fonts.googleapis.com/css?family=PT+Sans:400,400italic,700,700italic' rel='stylesheet' type='text/css'>

<style>
/* Kereső ikon az inputon belül */
.search-inline{ position:relative; }
.search-inline input.form-control{ padding-right:36px; }
.search-inline .search-btn{
  position:absolute; right:10px; top:50%;
  transform:translateY(-50%); border:0; background:transparent; padding:0; margin:0;
}
.search-inline .search-btn i{ color:#000; font-size:16px; }
.search-inline .search-btn:focus{ outline:0; }
</style>

<!--[if lt IE 9]>
<script src="https://oss.maxcdn.com/libs/html5shiv/3.7.0/html5shiv.js"></script>
<script src="https://oss.maxcdn.com/libs/respond.js/1.4.2/respond.min.js"></script>
<![endif]-->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','GTM-PWZ9ZWQ3');</script>
</head>
<body>
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-PWZ9ZWQ3" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>

<div id="wrap galery"> 
  <div class="container relative">
    <header>
      <div class="logo"><a href="#."><img class="img-responsive" src="images/logo.png" alt=""></a></div>
      <div class="top-bar">
        <div class="top-info">
          <ul class="personal-info">
            <li><p><i class="fa fa-phone"></i> +36 30 652 1858</p></li>
            <li><p><i class="fa fa-envelope-o"></i> <a href="mailto:info@dorozshidraulika.hu">info@dorozshidraulika.hu</a></p></li>
            <li><p><i class="fa fa-envelope-o"></i> <a href="mailto:dorozsmai@dorozshidraulika.hu">dorozsmai@dorozshidraulika.hu</a></p></li>
            <li><p class="uppercase"><i class="fa fa-map-o"></i> <a href="https://maps.app.goo.gl/jVABA5rucomqAJuv6" target="new" class="">KECSKEMÉT</a></p></li>
          </ul>
          <ul class="social"><li><a href="#."><i class="fa fa-facebook"></i></a></li></ul>
        </div>
        <nav class="navbar">
          <ul class="nav ownmenu">
            <li><a href="index.html">Főoldal</a></li>
            <li><a href="rolunk.html">Cégünkről</a></li>
            <li><a href="szolgaltatasaink.html">Szolgáltatásaink</a></li>
            <li><a href="termek_lista.html">Termékeink</a></li>
            <li><a href="galeria.html">Galéria</a></li>
            <li><a href="kapcsolat.html">Kapcsolat</a></li>
          </ul>
          <div class="quotes"><a href="ajanlatkeres.html" class="customcolor"><i class="fa fa-pencil-square-o"></i> Kérjen ajánlatot! </a></div>
        </nav>
      </div>
    </header>
  </div>

<!-- InstanceBeginEditable name="content" -->
<section class="sub-bnr" style="background: url('images/bg/termekek.jpg') center bottom; min-height: 480px">
  <div class="position-center-center">
    <div class="container">
      <h4>Terméklista</h4>
      <hr class="main">
    </div>
  </div>
  <div class="scroll"><a href="#content" class="go-down"></a></div>
</section>

<div id="content">
  <section class="padding-top-30 padding-bottom-20">
    <div class="container">
      <div class="blog blog-post">
        <div class="row margin-top-50">

          <!-- Side Bar -->
          <div class="col-md-3 padding-top-20 padding-bottom-20 bg-primary padding-left-25 padding-right-25">
            <div class="side-bar"> 
              <form method="get">
                <input type="hidden" name="cat" value="<?=htmlspecialchars($cat)?>">
                <input type="hidden" name="sub" value="<?=htmlspecialchars($sub)?>">
                <ul class="row">
                  <li class="col-sm-12 margin-bottom-30">
                    <select class="form-control radius-0" name="sort" onchange="this.form.submit()">
                      <option value="az"  <?= $sort==='az'?'selected':''; ?>>A-Z</option>
                      <option value="za"  <?= $sort==='za'?'selected':''; ?>>Z-A</option>
                      <option value="new" <?= $sort==='new'?'selected':''; ?>>Legújabbak</option>
                      <option value="old" <?= $sort==='old'?'selected':''; ?>>Legrégebbiek</option>
                    </select>
                  </li>
                  <li class="col-sm-12">
                    <div class="search-inline">
                      <input class="form-control" type="text" name="q"
                             value="<?=htmlspecialchars($q)?>" placeholder="Keresés">
                      <button class="search-btn" type="submit" title="Keresés">
                        <i class="fa fa-search"></i>
                      </button>
                    </div>
                  </li>
                  <?php if ($q !== ''): ?>
                  <li class="col-sm-12 margin-bottom-25">
                    <a class="btn btn-default btn-block margin-top-15" href="<?= urlWith(['q'=>null,'cat'=>null,'sub'=>null,'page'=>1]) ?>">
                      Szűrő törlése
                    </a>
                  </li>
                  <?php endif; ?>
                </ul>
              </form>

              <?php foreach ($cats as $c): ?>
                <?php
                  // Kereséskor csak találatos kategóriák jelenjenek meg
                  if ($q !== '' && empty($hitCatIds[$c['id']])) continue;

                  // Kereséskor a találatos alkategóriákat szűrjük le a listából
                  $subsList = $subsByCat[$c['id']] ?? [];
                  if ($q !== '') {
                    $hitsForCat = $hitSubsByCat[$c['id']] ?? [];
                    $subsList = array_values(array_filter($subsList, function($s) use ($hitsForCat){
                      return isset($hitsForCat['__ALL__']) || isset($hitsForCat[$s['id']]);
                    }));
                  }

                  $cIsNew     = is_new_badge($c);
                  $cActive    = ($c['slug'] === $cat);
                  $hasSubs    = !empty($subsList);
                  $collapseId = 'cat_'.$c['id'];
                ?>

                <?php if ($hasSubs): ?>
                  <div class="dropdown-cat">
                    <h5 class="side-tittle margin-top-20">
                      <a data-toggle="collapse"
                         href="#<?= $collapseId ?>"
                         aria-expanded="<?= $cActive ? 'true' : 'false' ?>"
                         aria-controls="<?= $collapseId ?>">
                        <?= htmlspecialchars($c['name']) ?>
                        <?php if ($cIsNew): ?><span class="badge badge-brand">ÚJ</span><?php endif; ?>
                        <i class="fa fa-angle-down pull-right"></i>
                      </a>
                    </h5>

                    <div id="<?= $collapseId ?>" class="collapse <?= $cActive ? 'in' : '' ?>">
                      <ul class="cate dropdown">
                        <li>
                          <a href="<?= urlWith(['cat'=>$c['slug'], 'sub'=>null, 'page'=>1]) ?>">
                            <strong>Összes</strong>
                          </a>
                        </li>
                        <?php foreach ($subsList as $s): ?>
                          <li>
                            <a href="<?= urlWith(['cat'=>$c['slug'],'sub'=>$s['slug'], 'page'=>1]) ?>">
                              <?= htmlspecialchars($s['name']) ?>
                            </a>
                            <?php if(is_new_badge($s)): ?><span class="red">ÚJ</span><?php endif; ?>
                          </li>
                        <?php endforeach; ?>
                      </ul>
                    </div>

                    <hr class="main">
                  </div>

                <?php else: ?>
                  <!-- Nincs (találatos) alkategória: egyszerű link a főkategóriára -->
                  <a href="<?= urlWith(['cat'=>$c['slug'], 'sub'=>null, 'page'=>1]) ?>">
                    <h5 class="side-tittle margin-top-50">
                      <?= htmlspecialchars($c['name']) ?>
                      <?php if ($cIsNew): ?><span class="badge badge-brand">ÚJ</span><?php endif; ?>
                    </h5>
                    <hr class="main">
                  </a>
                <?php endif; ?>

              <?php endforeach; ?>

            </div>
          </div>
          <!-- /Side Bar -->

          <div class="col-md-9 padding-left-50">

            <div class="heading text-left margin-bottom-10">
              <h3>
                <?= htmlspecialchars($activeCat['name'] ?? 'Összes kategória') ?>
                <?php if($activeSub): ?>
                  <span class="margin-left-30"><?= htmlspecialchars($activeSub['name']) ?></span>
                <?php endif; ?>
              </h3>
              <hr>
            </div>

            <?php if(!empty($activeCat['description'])): ?>
              <div class="content-text cate-desc margin-bottom-40">
                <?= $activeCat['description'] /* adminból jövő HTML megengedett */ ?>
              </div>
            <?php endif; ?>

            <div class="panel-group style-2" id="accordion">
              <?php if (!$items): ?>
                <p>Nincs megjeleníthető elem.</p>
              <?php endif; ?>

              <?php foreach ($items as $i):
                $pid        = 'item_'.$i['id'];
                $hasPdf     = !empty($pdfsBy[$i['id']]);
                $hasBody    = has_html($i['body_html']);
                $hasGallery = !empty($mediaBy[$i['id']]);

                $leftCol = ($hasBody && $hasPdf) ? 8 : ($hasBody ? 12 : 0);
                $rightCol= ($hasBody && $hasPdf) ? 4 : ($hasPdf ? 12 : 0);
              ?>
                <div class="panel panel-default">
                  <div class="panel-heading">
                    <h4 class="panel-title">
                      <a data-toggle="collapse" data-parent="#accordion" href="#<?= $pid ?>">
                        <span class="icon-accor"><i class="fa fa-download"></i></span>
                        <?= htmlspecialchars($i['title']) ?>
                        <?php if(is_new_badge($i)): ?><span class="badge badge-brand-right">ÚJ</span><?php endif; ?>
                      </a>
                    </h4>
                  </div>

                  <div id="<?= $pid ?>" class="panel-collapse collapse">
                    <div class="panel-body">
                      <div class="row">
                        <div class="col-md-12">

                          <?php if ($leftCol): ?>
                          <div class="col-md-<?= $leftCol ?> col-xs-12">
                            <h3 class="side-tittle">
                              <?= htmlspecialchars($i['title']) ?><br>
                              <?php if(!empty($i['short_text'])): ?>
                                <em class="small"><?= htmlspecialchars($i['short_text']) ?></em>
                              <?php endif; ?>
                            </h3>
                            <hr class="main">
                            <?php if ($hasBody): ?>
                              <div class="content-text">
                                <?= $i['body_html'] ?>
                              </div>
                            <?php endif; ?>
                          </div>
                          <?php endif; ?>

                          <?php if ($rightCol): ?>
                          <div class="col-md-<?= $rightCol ?> col-xs-12">
                            <?php if ($hasPdf): ?>
                              <h3 class="side-tittle">Letöltések</h3>
                              <hr class="main">
                              <ul class="list-group">
                                <?php foreach ($pdfsBy[$i['id']] as $p): ?>
                                  <li class="list-group-item">
                                    <a href="<?= htmlspecialchars($p['file_path']) ?>" target="_blank">
                                      <i class="fa fa-download margin-right-3"></i>
                                      <?= htmlspecialchars($p['display_name']) ?>
                                    </a>
                                  </li>
                                <?php endforeach; ?>
                              </ul>
                            <?php endif; ?>
                          </div>
                          <?php endif; ?>

                          <?php if ($hasGallery): ?>
                          <div class="col-md-12 margin-top-50">
                            <h3 class="side-tittle">Galéria</h3>
                            <hr class="main">
                            <ul class="items col-12 row gallery">
                              <?php foreach ($mediaBy[$i['id']] as $m): ?>
                                <li class="item col-md-3 building construction renovate">
                                  <div class="gal-item">
                                    <a href="<?= htmlspecialchars($m['file_path']) ?>">
                                      <img src="<?= htmlspecialchars($m['file_path']) ?>" alt="<?= htmlspecialchars($m['alt'] ?? '') ?>" class="img-responsive">
                                    </a>
                                  </div>
                                </li>
                              <?php endforeach; ?>
                            </ul>
                          </div>
                          <?php endif; ?>

                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <?php if ($total > $perPage): ?>
            <nav class="text-center margin-top-20 margin-bottom-50" aria-label="Oldalozás">
              <ul class="pagination">
                <?php $prev = max(1, $page-1); $next = min($pages, $page+1); ?>
                <li class="<?= $page<=1 ? 'disabled' : '' ?>">
                  <a href="<?= $page<=1 ? '#' : urlWith(['page'=>$prev]) ?>" aria-label="Előző">&laquo;</a>
                </li>
                <?php for ($p=1; $p<=$pages; $p++): ?>
                  <li class="<?= $p===$page ? 'active' : '' ?>">
                    <?php if ($p===$page): ?>
                      <span><?= $p ?></span>
                    <?php else: ?>
                      <a href="<?= urlWith(['page'=>$p]) ?>"><?= $p ?></a>
                    <?php endif; ?>
                  </li>
                <?php endfor; ?>
                <li class="<?= $page>=$pages ? 'disabled' : '' ?>">
                  <a href="<?= $page>=$pages ? '#' : urlWith(['page'=>$next]) ?>" aria-label="Következő">&raquo;</a>
                </li>
              </ul>
            </nav>
            <?php endif; ?>

          </div>

        </div>
      </div>
    </div>
  </section>

</div>
<!-- /CONTENT -->

<!-- InstanceEndEditable -->	
<section class="sub-footer">
  <div class="container">
    <ul class="row no-margin">
      <li class="col-sm-4">
        <div class="icon"><i class="fa fa-phone"></i></div>
        <h6>Telefon</h6>
        <p>+36 30 652 1858</p>
      </li>
      <li class="col-sm-8">
        <div class="icon"><i class="fa fa-envelope-o"></i></div>
        <h6>Email</h6>
        <p>info@dorozshidraulika.hu; dorozsmai@dorozshidraulika.hu</p>
      </li>
    </ul>
  </div>
</section>  

<div class="rights">
  <p> Copyright &copy; 2024 - Minden jog fenntartva! - <a href="impresszum.html" target="new" style="color:white">Impresszum</a> - <a href="download/gdpr.pdf" target="new" style="color:white">Adatkezelési tájékoztató</a> - <a href="http://iparifoto.hu" target="new" style="color:white">IpariFotó</a></p>
</div>
</div>

<script src="js/jquery-1.11.0.min.js"></script> 
<script src="js/bootstrap.min.js"></script> 
<script src="js/own-menu.js"></script> 
<script src="js/jquery.isotope.min.js"></script> 
<script src="js/jquery.prettyPhoto.js"></script> 
<script src="js/owl.carousel.min.js"></script> 
<script type="text/javascript" src="https://cdn.jsdelivr.net/npm/cookie-bar/cookiebar-latest.min.js?theme=minimal&thirdparty=1&always=1&showNoConsent=1&privacyPage=www.dorozsmai.hu%2Fdownload%2Fgdpr.pdf"></script>
<script type="text/javascript" src="rs-plugin/js/jquery.tp.t.min.js"></script> 
<script type="text/javascript" src="rs-plugin/js/jquery.tp.min.js"></script> 
<script src="js/main.js"></script>
<script type="text/javascript" src="js/simple-lightbox.js"></script>
<script>
  $(function(){
    var $gallery = $('.gallery a').simpleLightbox();
    $gallery.on('error.simplelightbox', function(e){ console.log(e); });
  });
</script>
</body>
<!-- InstanceEnd -->
</html>
