<?php
ini_set('display_errors',1); error_reporting(E_ALL);

require __DIR__.'/../inc/bootstrap.php';
require __DIR__.'/../inc/auth.php';
require_admin();

/* -------- flash (session) -------- */
if (!isset($_SESSION)) { session_start(); }
$flash = isset($_SESSION['flash']) ? $_SESSION['flash'] : ['type'=>'','msg'=>''];
unset($_SESSION['flash']);

/* -------- helpers -------- */
function slugify($s){
  $s = iconv('UTF-8','ASCII//TRANSLIT',$s);
  $s = strtolower(trim($s));
  $s = preg_replace('~[^a-z0-9]+~','-',$s);
  return trim($s,'-') ?: uniqid();
}
function ensure_dir($path){ if (!is_dir($path)) @mkdir($path, 0775, true); }
function save_upload($field, $destDir, $allow, $max=10485760){ // 10MB
  $out = [];
  if (empty($_FILES[$field]) || empty($_FILES[$field]['name'])) return $out;
  ensure_dir($destDir);

  $names = $_FILES[$field]['name'];
  $tmpns = $_FILES[$field]['tmp_name'];
  $errs  = $_FILES[$field]['error'];
  $sizes = $_FILES[$field]['size'];

  $count = is_array($names) ? count($names) : 0;
  $fi = class_exists('finfo') ? new finfo(FILEINFO_MIME_TYPE) : null;

  for ($i=0; $i<$count; $i++){
    if ($errs[$i] !== UPLOAD_ERR_OK || $sizes[$i] <= 0) continue;
    if ($sizes[$i] > $max) continue;

    $tmp  = $tmpns[$i];
    $mime = $fi ? $fi->file($tmp) : 'application/octet-stream';
    if (!in_array($mime, $allow, true)) continue;

    $ext  = strtolower(pathinfo($names[$i], PATHINFO_EXTENSION));
    $name = uniqid().'.'.$ext;
    $to   = rtrim($destDir,'/').'/'.$name;

    if (move_uploaded_file($tmp, $to)) $out[] = $to;
  }
  return $out;
}

/* -------- upload gyökerek -------- */
$uploadImg = __DIR__.'/uploads/img';
$uploadPdf = __DIR__.'/uploads/pdf';
ensure_dir($uploadImg);
ensure_dir($uploadPdf);

/* -------- POST akciók + PRG redirect a megfelelő fülre -------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = isset($_POST['action']) ? $_POST['action'] : '';

  // 1) Főkategória mentése (új vagy upsert / meglévő kiválasztása)
  if ($action === 'save_cat') {
    $name = trim($_POST['cat_name'] ?? '');
    $desc = trim($_POST['cat_desc'] ?? '');
    $selectExisting = (int)($_POST['cat_existing'] ?? 0);

    if ($selectExisting > 0) {
      $_SESSION['wizard']['cat_id'] = $selectExisting;
      $_SESSION['flash'] = ['type'=>'success','msg'=>'Főkategória kiválasztva.'];
      header('Location: '.$_SERVER['PHP_SELF'].'?tab=step2'); exit;
    }

    if ($name === '') {
      $_SESSION['flash'] = ['type'=>'danger','msg'=>'A főkategória neve kötelező (vagy válassz meglévőt).'];
      header('Location: '.$_SERVER['PHP_SELF'].'?tab=step1'); exit;
    }

    $slug = slugify($name);
    $st = $pdo->prepare(
      "INSERT INTO categories(name,slug,description,is_new)
       VALUES (:n,:s,:d,1)
       ON CONFLICT(slug) DO UPDATE SET name=excluded.name, description=excluded.description, is_new=1"
    );
    $st->execute([':n'=>$name, ':s'=>$slug, ':d'=>$desc]);

    $row = fetchOne($pdo, "SELECT id FROM categories WHERE slug=:s LIMIT 1", [':s'=>$slug]);
    if ($row) { $_SESSION['wizard']['cat_id'] = (int)$row['id']; }

    $_SESSION['flash'] = ['type'=>'success','msg'=>'Főkategória elmentve.'];
    header('Location: '.$_SERVER['PHP_SELF'].'?tab=step2'); exit;
  }

  // 2) Alkategória mentése (új vagy meglévő választása)
  if ($action === 'save_subcat') {
    $catId = (int)($_POST['sub_parent'] ?? 0);
    $existingSub = (int)($_POST['sub_existing'] ?? 0);
    $name  = trim($_POST['sub_name'] ?? '');

    if ($catId <= 0) {
      $_SESSION['flash'] = ['type'=>'danger','msg'=>'Főkategória kiválasztása kötelező.'];
      header('Location: '.$_SERVER['PHP_SELF'].'?tab=step2'); exit;
    }

    $_SESSION['wizard']['cat_id'] = $catId;

    if ($existingSub > 0) {
      $_SESSION['wizard']['sub_id'] = $existingSub;
      $_SESSION['flash'] = ['type'=>'success','msg'=>'Alkategória kiválasztva.'];
      header('Location: '.$_SERVER['PHP_SELF'].'?tab=step3'); exit;
    }

    if ($name === '') {
      $_SESSION['flash'] = ['type'=>'danger','msg'=>'Add meg az alkategória nevét, vagy válassz meglévőt.'];
      header('Location: '.$_SERVER['PHP_SELF'].'?tab=step2'); exit;
    }

    $slug = slugify($name);
    $st = $pdo->prepare(
      "INSERT INTO subcategories(category_id,name,slug,is_new)
       VALUES (:c,:n,:s,1)
       ON CONFLICT(category_id,slug) DO UPDATE SET name=excluded.name, is_new=1"
    );
    $st->execute([':c'=>$catId, ':n'=>$name, ':s'=>$slug]);

    $row = fetchOne($pdo, "SELECT id FROM subcategories WHERE category_id=:c AND slug=:s LIMIT 1", [':c'=>$catId, ':s'=>$slug]);
    if ($row) { $_SESSION['wizard']['sub_id'] = (int)$row['id']; }

    $_SESSION['flash'] = ['type'=>'success','msg'=>'Alkategória elmentve.'];
    header('Location: '.$_SERVER['PHP_SELF'].'?tab=step3'); exit;
  }

  // 3) Katalógus mentése (cím + legalább 1 PDF kötelező)
  if ($action === 'save_catalog') {
    $title = trim($_POST['cl_title'] ?? '');
    $short = trim($_POST['cl_short'] ?? '');
    $catId = (int)($_POST['cl_cat'] ?? 0);
    if ($catId <= 0 && !empty($_SESSION['wizard']['cat_id'])) $catId = (int)$_SESSION['wizard']['cat_id'];
    $subId = (isset($_POST['cl_sub']) && $_POST['cl_sub']!=='') ? (int)$_POST['cl_sub'] : (isset($_SESSION['wizard']['sub_id']) ? (int)$_SESSION['wizard']['sub_id'] : null);
    $body  = $_POST['cl_body_html'] ?? '';

    // kötelező: cím + min. 1 PDF kiválasztva
    $pdfSelected = 0;
    if (!empty($_FILES['pdf_file']['name']) && is_array($_FILES['pdf_file']['name'])) {
      foreach ($_FILES['pdf_file']['name'] as $i=>$nm) {
        if (!empty($nm) && (int)($_FILES['pdf_file']['size'][$i] ?? 0) > 0) { $pdfSelected++; }
      }
    }

    if ($title==='' || $catId<=0) {
      $_SESSION['flash'] = ['type'=>'danger','msg'=>'Cím és főkategória kötelező.'];
      header('Location: '.$_SERVER['PHP_SELF'].'?tab=step3'); exit;
    }
    if ($pdfSelected === 0) {
      $_SESSION['flash'] = ['type'=>'danger','msg'=>'Legalább egy PDF fájl feltöltése kötelező.'];
      header('Location: '.$_SERVER['PHP_SELF'].'?tab=step3'); exit;
    }

    // Előbb mentsük a PDF-eket (ha 0 mentődött, ne hozzunk létre katalógust)
    $pdfFiles = save_upload('pdf_file', $uploadPdf, ['application/pdf']);
    if (count($pdfFiles) === 0) {
      $_SESSION['flash'] = ['type'=>'danger','msg'=>'A PDF feltöltés nem sikerült (formátum/méret).'];
      header('Location: '.$_SERVER['PHP_SELF'].'?tab=step3'); exit;
    }

    $slug = slugify($title);
    $st = $pdo->prepare(
      "INSERT INTO catalogs(category_id, subcategory_id, title, short_text, body_html, slug, is_new, sort)
       VALUES (:c,:s,:t,:sh,:b,:sl,1,0)"
    );
    $st->execute([
      ':c'=>$catId, ':s'=>$subId, ':t'=>$title, ':sh'=>$short, ':b'=>$body, ':sl'=>$slug
    ]);
    $catalogId = (int)$pdo->lastInsertId();

    // PDF meta
    $pdfNames = $_POST['pdf_name'] ?? [];
    foreach ($pdfFiles as $idx=>$absPath) {
      $webPath = 'admin/uploads/pdf/'.basename($absPath);
      $name = isset($pdfNames[$idx]) && $pdfNames[$idx] !== '' ? $pdfNames[$idx] : ('Katalógus '.($idx+1));
      $st = $pdo->prepare("INSERT INTO catalog_pdfs(catalog_id,display_name,file_path) VALUES(:id,:n,:p)");
      $st->execute([':id'=>$catalogId, ':n'=>$name, ':p'=>$webPath]);
    }

    // Galéria képek (opcionális)
    $alts = $_POST['gallery_alt'] ?? [];
    $imgFiles = save_upload('gallery_file', $uploadImg, ['image/jpeg','image/png','image/gif','image/webp']);
    foreach ($imgFiles as $idx=>$absPath) {
      $webPath = 'admin/uploads/img/'.basename($absPath);
      $alt = $alts[$idx] ?? '';
      $st = $pdo->prepare("INSERT INTO catalog_media(catalog_id,file_path,alt) VALUES(:id,:p,:a)");
      $st->execute([':id'=>$catalogId, ':p'=>$webPath, ':a'=>$alt]);
    }

    unset($_SESSION['wizard']);
    $_SESSION['flash'] = ['type'=>'success','msg'=>'Katalógus elmentve.'];
    header('Location: '.$_SERVER['PHP_SELF'].'?tab=step4'); exit;
  }

  // 4) Főkategória törlése (csak ha üres)
  if ($action === 'delete_cat') {
    $catId = (int)($_POST['cat_id'] ?? 0);
    if ($catId <= 0) {
      $_SESSION['flash'] = ['type'=>'danger','msg'=>'Hiányzó főkategória-azonosító.'];
      header('Location: '.$_SERVER['PHP_SELF'].'?tab=step1'); exit;
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM subcategories WHERE category_id=:id");
    $st->execute([':id'=>$catId]);
    $hasSubs = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM catalogs WHERE category_id=:id");
    $st->execute([':id'=>$catId]);
    $hasCats = (int)$st->fetchColumn();

    if ($hasSubs > 0 || $hasCats > 0) {
      $_SESSION['flash'] = ['type'=>'danger','msg'=>'A főkategória nem törölhető: van alatta alkategória vagy katalógus. Előbb azokat töröld / helyezd át.'];
      header('Location: '.$_SERVER['PHP_SELF'].'?tab=step1'); exit;
    }

    $st = $pdo->prepare("DELETE FROM categories WHERE id=:id");
    $st->execute([':id'=>$catId]);
    $_SESSION['flash'] = ['type'=>'success','msg'=>'Főkategória törölve.'];
    header('Location: '.$_SERVER['PHP_SELF'].'?tab=step1'); exit;
  }

  // 5) Alkategória törlése (csak ha nincs hozzárendelt katalógus)
  if ($action === 'delete_subcat') {
    $subId = (int)($_POST['sub_id'] ?? 0);
    if ($subId <= 0) {
      $_SESSION['flash'] = ['type'=>'danger','msg'=>'Hiányzó alkategória-azonosító.'];
      header('Location: '.$_SERVER['PHP_SELF'].'?tab=step2'); exit;
    }

    $st = $pdo->prepare("SELECT COUNT(*) FROM catalogs WHERE subcategory_id=:id");
    $st->execute([':id'=>$subId]);
    $hasCats = (int)$st->fetchColumn();

    if ($hasCats > 0) {
      $_SESSION['flash'] = ['type'=>'danger','msg'=>'Az alkategória nem törölhető: van hozzárendelt katalógus. Előbb azokat töröld / helyezd át.'];
      header('Location: '.$_SERVER['PHP_SELF'].'?tab=step2'); exit;
    }

    $st = $pdo->prepare("DELETE FROM subcategories WHERE id=:id");
    $st->execute([':id'=>$subId]);
    $_SESSION['flash'] = ['type'=>'success','msg'=>'Alkategória törölve.'];
    header('Location: '.$_SERVER['PHP_SELF'].'?tab=step2'); exit;
  }
}

/* -------- aktív fül beállítása (GET paraméterből) -------- */
$activeTab = 'step1';
if (!empty($_GET['tab'])) {
  $t = preg_replace('~[^a-z0-9_]~i','', $_GET['tab']);
  if (in_array($t, ['step1','step2','step3','stepSEO','step4'], true)) $activeTab = $t;
}

/* -------- adatok a selectekhez -------- */
$cats = fetchAll($pdo, "SELECT id,name,slug FROM categories ORDER BY name");
$subs = fetchAll($pdo, "SELECT id,category_id,name,slug FROM subcategories ORDER BY name");
$subsByCat = [];
foreach($cats as $c) $subsByCat[$c['id']] = [];
foreach($subs as $s) $subsByCat[$s['category_id']][] = $s;

// wizard állapot előtöltés (ha van)
$wizardCatId = isset($_SESSION['wizard']['cat_id']) ? (int)$_SESSION['wizard']['cat_id'] : 0;
$wizardSubId = isset($_SESSION['wizard']['sub_id']) ? (int)$_SESSION['wizard']['sub_id'] : 0;
?>
<!DOCTYPE html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <title>Dorozs – Admin (Wizard + Súgó)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap 3 + Font Awesome -->
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.5/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css">

  <!-- Saját CSS (gyökér /css mappa!) -->
  <link rel="stylesheet" href="../css/login.css">
  <style>
    .muted-preview{color:#888}
    .rtf-editor{min-height:150px;border:1px solid #ddd;padding:8px;border-radius:4px;background:#fff}
    .wizard-actions{margin:10px 0 30px}
    .badge-brand{background:#d9534f}

  </style>
</head>
<body>
<!-- FELSŐ PIROS SÁV -->
<div class="topbar">
  <div class="container">
    <div class="pull-left">
      <a href="index.php" class="btn btn-outline-light btn-sm">
        <i class="fa fa-list"></i> Vissza a listához
      </a>
    </div>
    <div class="pull-right">
      <a href="logout.php" class="btn btn-outline-light btn-sm">
        <i class="fa fa-sign-out"></i> Kijelentkezés
      </a>
    </div>
    <div class="clearfix"></div>
  </div>
</div>

<div class="container">
  <!-- Fejléc -->
  <div class="text-center" style="margin:20px 0 10px;">
    <img src="../images/logo.png" alt="Dorozs" style="max-height:36px;">
    <h3 style="margin-top:10px;">Katalógus admin</h3>
  </div>

  <?php if($flash['type']): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['msg']) ?></div>
  <?php endif; ?>

  <!-- Lépésjelző -->
  <ul class="nav nav-pills nav-justified stepper" role="tablist">
    <li role="presentation" class="active"><a href="#step1" data-toggle="tab"><strong>1.</strong> Főkategória</a></li>
    <li role="presentation"><a href="#step2" data-toggle="tab"><strong>2.</strong> Alkategória</a></li>
    <li role="presentation"><a href="#step3" data-toggle="tab"><strong>3.</strong> Katalógus</a></li>
    <li role="presentation"><a href="#stepSEO" data-toggle="tab"><strong>4.</strong> SEO</a></li>
    <li role="presentation"><a href="#step4" data-toggle="tab"><strong>5.</strong> Összegzés</a></li>
  </ul>

  <div class="tab-content">

    <!-- 1. Főkategória -->
    <div role="tabpanel" class="tab-pane fade in active" id="step1">
      <div class="panel panel-default">
        <div class="panel-heading">
          <strong>Főkategória rögzítése</strong>
          <button type="button" class="btn btn-default btn-xs pull-right" id="btnDeleteCategory" style="margin-right:6px;">
            <i class="fa fa-trash"></i> Törlés
          </button>
          <button type="button" class="btn btn-danger btn-xs pull-right" id="saveCategory">
            <i class="fa fa-save"></i> Mentés (csak főkategória)
          </button>
        </div>
        <div class="panel-body">
          <form class="form-horizontal" id="form-cat" method="post">
            <input type="hidden" name="action" value="save_cat">

            <!-- Meglévő főkategória választó -->
            <div class="form-group">
              <label class="col-sm-3 control-label">Meglévő kiválasztása</label>
              <div class="col-sm-9">
                <select class="form-control" id="cat_existing" name="cat_existing">
                  <option value="0">— válassz meglévőt —</option>
                  <?php foreach($cats as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $wizardCatId===(int)$c['id']?'selected':'' ?>>
                      <?= htmlspecialchars($c['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <p class="help">Választhatsz meglévőt, vagy alább új főkategóriát adhatsz meg.</p>
              </div>
            </div>

            <div class="form-group" id="cat_name_wrap">
              <label class="col-sm-3 control-label req">Név</label>
              <div class="col-sm-9">
                <input type="text" class="form-control" id="cat_name" name="cat_name" placeholder="Pl.: BVA hidraulika">
                <p class="help">A nyilvános oldalon ez a cím jelenik meg.</p>
              </div>
            </div>
            <div class="form-group" id="cat_url_wrap">
              <label class="col-sm-3 control-label">URL előnézet</label>
              <div class="col-sm-9">
                <p class="form-control-static muted-preview" id="cat_url_preview">/bva-hidraulika/</p>
              </div>
            </div>
            <div class="form-group" id="cat_desc_wrap">
              <label class="col-sm-3 control-label">Leírás</label>
              <div class="col-sm-9">
                <textarea class="form-control" rows="3" id="cat_desc" name="cat_desc" placeholder="Rövid leírás…"></textarea>
              </div>
            </div>
            <div class="alert alert-info" id="cat_info">
              Az „ÚJ” jelölés automatikusan kerül ki, ha új tartalom jelenik meg.
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- 2. Alkategória -->
    <div role="tabpanel" class="tab-pane fade" id="step2">
      <div class="panel panel-default">
        <div class="panel-heading">
          <strong>Alkategória rögzítése</strong>
          <button type="button" class="btn btn-default btn-xs pull-right" id="btnDeleteSubCategory" style="margin-right:6px;">
            <i class="fa fa-trash"></i> Törlés
          </button>
          <button type="button" class="btn btn-danger btn-xs pull-right" id="saveSubCategory">
            <i class="fa fa-save"></i> Mentés (csak alkategória)
          </button>
        </div>
        <div class="panel-body">
          <form class="form-horizontal" id="form-subcat" method="post">
            <input type="hidden" name="action" value="save_subcat">
            <div class="form-group" id="sub_parent_wrap">
              <label class="col-sm-3 control-label req">Főkategória</label>
              <div class="col-sm-9">
                <select class="form-control" id="sub_parent" name="sub_parent">
                  <?php foreach($cats as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $wizardCatId===(int)$c['id']?'selected':'' ?>>
                      <?= htmlspecialchars($c['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <p class="help">A nyilvános oldalon ehhez a főkategóriához kerül.</p>
              </div>
            </div>

            <!-- Meglévő alkategória választó -->
            <div class="form-group">
              <label class="col-sm-3 control-label">Meglévő alkategória</label>
              <div class="col-sm-9">
                <select class="form-control" id="sub_existing" name="sub_existing">
                  <option value="0">— válassz meglévőt —</option>
                  <!-- JS tölti a szülő alapján -->
                </select>
                <p class="help">Választhatsz meglévőt, vagy alább új alkategóriát adhatsz meg.</p>
              </div>
            </div>

            <div class="form-group" id="sub_name_wrap">
              <label class="col-sm-3 control-label req">Név</label>
              <div class="col-sm-9">
                <input type="text" class="form-control" id="sub_name" name="sub_name" placeholder="Pl.: Munkahengerek">
              </div>
            </div>
            <div class="form-group" id="sub_url_wrap">
              <label class="col-sm-3 control-label">URL előnézet</label>
              <div class="col-sm-9">
                <p class="form-control-static muted-preview" id="sub_url_preview">/bva-hidraulika/munkahengerek/</p>
              </div>
            </div>
            <div class="alert alert-info" id="sub_info">
              Az alkategória „ÚJ” jelölése a nyilvános oldalon piros szövegként jelenik meg.
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- 3. Katalógus -->
    <div role="tabpanel" class="tab-pane fade" id="step3">
      <div class="panel panel-default">
        <div class="panel-heading">
          <strong>Katalógus rögzítése</strong>
          <span id="preview_new_badge" class="badge badge-brand pull-right" style="display:none;">ÚJ</span>
        </div>
        <div class="panel-body">
          <form class="form-horizontal" id="form-catalog" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save_catalog">
            <input type="hidden" name="cl_body_html" id="cl_body_html">
            <!-- rejtett mezők a kiválasztott kat./alkat. továbbviteléhez -->
            <input type="hidden" name="cl_cat" id="cl_cat_hidden" value="<?= $wizardCatId ?: '' ?>">
            <input type="hidden" name="cl_sub" id="cl_sub_hidden" value="<?= $wizardSubId ?: '' ?>">

            <div class="form-group" id="cl_title_wrap">
              <label class="col-sm-3 control-label req">Cím</label>
              <div class="col-sm-9">
                <input type="text" class="form-control" id="cl_title" name="cl_title" placeholder="Pl.: Letölthető elem">
              </div>
            </div>

            <div class="form-group" id="cl_short_wrap">
              <label class="col-sm-3 control-label">Rövid leírás</label>
              <div class="col-sm-9">
                <input type="text" class="form-control" id="cl_short" name="cl_short" placeholder="Pl.: Termékrövidleírása">
              </div>
            </div>

            <div class="form-group" id="cl_url_wrap">
              <label class="col-sm-3 control-label">Katalógus URL</label>
              <div class="col-sm-9">
                <p class="form-control-static muted-preview" id="cl_url_preview">/bva-hidraulika/munkahengerek/letoltheto-elem/</p>
              </div>
            </div>

            <!-- Leírás formázható -->
            <div class="form-group" id="rtf_wrap">
              <label class="col-sm-3 control-label">Leírás (formázható)</label>
              <div class="col-sm-9">
                <div class="btn-toolbar rtf-toolbar" role="toolbar" style="margin-bottom:6px;">
                  <div class="btn-group">
                    <button type="button" class="btn btn-default btn-sm" data-cmd="bold" title="Félkövér"><i class="fa fa-bold"></i></button>
                    <button type="button" class="btn btn-default btn-sm" id="btn-ul" title="Felsorolás"><i class="fa fa-list-ul"></i></button>
                  </div>
                </div>
                <div id="rtf" class="rtf-editor" contenteditable="true">
                  <p>Ide írhatod a leírást…</p>
                </div>
              </div>
            </div>

            <!-- Galéria -->
            <div class="form-group" id="gallery_wrap_group">
              <label class="col-sm-3 control-label">Galéria képek</label>
              <div class="col-sm-9">
                <div id="gallery_wrap">
                  <div class="row gallery-row" style="margin-bottom:8px;">
                    <div class="col-sm-8"><input type="file" class="form-control" name="gallery_file[]"></div>
                    <div class="col-sm-4"><input type="text" class="form-control" name="gallery_alt[]" placeholder="Cím / alt"></div>
                  </div>
                </div>
                <button type="button" id="btnAddGallery" class="btn btn-default btn-sm"><i class="fa fa-plus"></i> Kép mező</button>
                <span class="help">Maximum 4 galériakép mező.</span>
              </div>
            </div>

            <!-- PDF-ek -->
            <div class="form-group" id="pdf_wrap_group">
              <label class="col-sm-3 control-label">PDF fájlok</label>
              <div class="col-sm-9">
                <div id="pdf_wrap">
                  <div class="row pdf-row" style="margin-bottom:8px;">
                    <div class="col-sm-6"><input type="text" class="form-control" name="pdf_name[]" placeholder="Megjelenő név"></div>
                    <div class="col-sm-5"><input type="file" class="form-control" name="pdf_file[]"></div>
                    <div class="col-sm-1"><button type="button" class="btn btn-link text-danger btnRemovePdf"><i class="fa fa-times"></i></button></div>
                  </div>
                </div>
                <button type="button" id="btnAddPdf" class="btn btn-default btn-sm"><i class="fa fa-plus"></i> PDF hozzáadása</button>
              </div>
            </div>

          </form>
        </div>
      </div>
    </div>

    <!-- 4. SEO (UI – mentést később kötjük be külön táblába) -->
    <div role="tabpanel" class="tab-pane fade" id="stepSEO">
      <div class="panel panel-default">
        <div class="panel-heading"><strong>SEO és Open Graph adatok</strong></div>
        <div class="panel-body">
          <form class="form-horizontal" id="form-seo">
            <div class="form-group" id="seo_title_wrap">
              <label class="col-sm-3 control-label">Meta Title</label>
              <div class="col-sm-9"><input type="text" class="form-control" id="seo_title"></div>
            </div>
            <div class="form-group" id="seo_desc_wrap">
              <label class="col-sm-3 control-label">Meta Description</label>
              <div class="col-sm-9"><textarea class="form-control" rows="3" id="seo_desc"></textarea></div>
            </div>
            <div class="form-group" id="og_title_wrap">
              <label class="col-sm-3 control-label">OG Title</label>
              <div class="col-sm-9"><input type="text" class="form-control" id="og_title"></div>
            </div>
            <div class="form-group" id="og_desc_wrap">
              <label class="col-sm-3 control-label">OG Description</label>
              <div class="col-sm-9"><textarea class="form-control" rows="3" id="og_desc"></textarea></div>
            </div>
            <div class="form-group" id="og_type_wrap">
              <label class="col-sm-3 control-label">OG Type</label>
              <div class="col-sm-3">
                <select class="form-control" id="og_type">
                  <option value="article">article</option>
                  <option value="website">website</option>
                  <option value="product">product</option>
                </select>
              </div>
              <label class="col-sm-3 control-label">OG Image</label>
              <div class="col-sm-3">
                <input type="file" class="form-control" id="og_image">
              </div>
            </div>
            <div class="form-group" id="seo_url_wrap">
              <label class="col-sm-3 control-label">Canonical / OG URL</label>
              <div class="col-sm-9">
                <p class="form-control-static muted-preview" id="seo_url_preview">
                  https://dorozshidraulika.hu<b id="seo_url_path">/bva-hidraulika/munkahengerek/letoltheto-elem/</b>
                </p>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- 5. Összegzés -->
    <div role="tabpanel" class="tab-pane fade" id="step4">
      <div class="panel panel-default">
        <div class="panel-heading"><strong>Összegzés</strong></div>
        <div class="panel-body">
          <div class="row">
            <div class="col-sm-4">
              <h4>Főkategória</h4>
              <ul class="list-unstyled">
                <li><strong>Név:</strong> <span id="sum_cat_name">–</span></li>
                <li><strong>URL:</strong> <span id="sum_cat_url">–</span></li>
              </ul>
            </div>
            <div class="col-sm-4">
              <h4>Alkategória</h4>
              <ul class="list-unstyled">
                <li><strong>Szülő:</strong> <span id="sum_sub_parent">–</span></li>
                <li><strong>Név:</strong> <span id="sum_sub_name">–</span></li>
                <li><strong>URL:</strong> <span id="sum_sub_url">–</span></li>
              </ul>
            </div>
            <div class="col-sm-4">
              <h4>Katalógus</h4>
              <ul class="list-unstyled">
                <li><strong>Cím:</strong> <span id="sum_cl_title">–</span></li>
                <li><strong>URL:</strong> <span id="sum_cl_url">–</span></li>
              </ul>
            </div>
          </div>
          <h4>SEO</h4>
          <ul class="list-unstyled">
            <li><strong>Meta Title:</strong> <span id="sum_seo_title">–</span></li>
            <li><strong>Meta Description:</strong> <span id="sum_seo_desc">–</span></li>
            <li><strong>OG Title:</strong> <span id="sum_og_title">–</span></li>
            <li><strong>OG Description:</strong> <span id="sum_og_desc">–</span></li>
            <li><strong>OG Type:</strong> <span id="sum_og_type">–</span></li>
            <li><strong>Canonical/OG URL:</strong> <span id="sum_seo_url">–</span></li>
          </ul>
          <div class="alert alert-info">Demó UI – a SEO mentését később kötjük be.</div>
        </div>
      </div>
    </div>
  </div>

  <!-- NAV gombok -->
  <div class="wizard-actions">
    <div class="row">
      <div class="col-xs-6">
        <button class="btn btn-default" id="btnPrev"><i class="fa fa-angle-left"></i> Vissza</button>
      </div>
      <div class="col-xs-6 text-right">
        <button class="btn btn-danger" id="btnNext">Tovább <i class="fa fa-angle-right"></i></button>
        <button class="btn btn-success" id="btnSave" style="display:none;"><i class="fa fa-save"></i> Mentés</button>
      </div>
    </div>
  </div>
</div>

<!-- Láthatatlan törlés-űrlapok -->
<form id="form-del-cat" method="post" style="display:none;">
  <input type="hidden" name="action" value="delete_cat">
  <input type="hidden" name="cat_id" id="del_cat_id">
</form>
<form id="form-del-sub" method="post" style="display:none;">
  <input type="hidden" name="action" value="delete_subcat">
  <input type="hidden" name="sub_id" id="del_sub_id">
</form>

<!-- Súgó overlay és gomb -->
<div id="helpOverlay"></div>
<button id="helpFab" class="btn btn-danger btn-lg" title="Súgó">
  <i class="fa fa-question"></i>
</button>

<!-- jQuery + Bootstrap -->
<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.3/jquery.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.5/js/bootstrap.min.js"></script>

<!-- Saját JS (gyökérből) -->
<script src="/js/login.js"></script>
<script>
(function(){
  var activeTab = '<?= isset($activeTab) ? $activeTab : "step1" ?>';
  var SUBS_BY_CAT = <?= json_encode($subsByCat, JSON_UNESCAPED_UNICODE) ?>;

  // rejtett mezők, melyek Step3 POST-jába mennek
  function setCatHidden(val){ $('#cl_cat_hidden').val(val || ''); }
  function setSubHidden(val){ $('#cl_sub_hidden').val(val || ''); }

  function wireSaveForTab(tabId){
    $('#btnSave').off('click');
    if (tabId === '#step3') {
      // STEP3: valódi mentés (POST) + kliens oldali kötelezők
      $('#btnSave').on('click', function(e){
        e.preventDefault();
        var title = ($('#cl_title').val() || '').trim();
        if (!title) { alert('A katalógus címe kötelező.'); return; }
        var hasPdf = $('input[name="pdf_file[]"]').filter(function(){
          return this.files && this.files.length > 0;
        }).length > 0;
        if (!hasPdf) { alert('Legalább egy PDF fájl feltöltése kötelező.'); return; }
        $('#cl_body_html').val($('#rtf').html());
        $('#form-catalog').trigger('submit');
      });
    } else if (tabId === '#step4') {
      // STEP4 (Összegzés): vissza a listához
      $('#btnSave').on('click', function(e){
        e.preventDefault();
        window.location.href = 'index.php';
      });
    }
  }

  function updateNav(){
    var $items = $('.stepper li');
    var $active = $items.filter('.active');
    var idx = $items.index($active);
    var last = $items.length - 1;
    $('#btnPrev').prop('disabled', idx <= 0);
    if (idx >= last) { $('#btnNext').hide(); $('#btnSave').show(); }
    else { $('#btnNext').show(); $('#btnSave').hide(); }
    var tabId = $active.find('a').attr('href') || '#step1';
    wireSaveForTab(tabId);
  }

  // Alkategória lista feltöltése a parent alapján
  function fillSubExisting(parentId, preselect){
    var list = SUBS_BY_CAT[parentId] || [];
    var $s = $('#sub_existing').empty().append('<option value="0">— válassz meglévőt —</option>');
    for (var i=0;i<list.length;i++){
      var opt = $('<option>').val(list[i].id).text(list[i].name);
      if (preselect && parseInt(preselect,10)===parseInt(list[i].id,10)) opt.attr('selected','selected');
      $s.append(opt);
    }
  }

  $(function(){
    // induló fül (POST utáni redirect ?tab=...)
    if (activeTab && activeTab !== 'step1') {
      $('.stepper a[href="#'+activeTab+'"]').tab('show');
    }
    updateNav();

    // fülváltáskor gombok és Save viselkedés frissítése
    $(document).on('shown.bs.tab', '.stepper a[data-toggle="tab"]', updateNav);

    // alsó Tovább/Vissza — STEP1-ben ellenőrzünk, STEP2-ben elég a főkategória
    $('#btnNext').off('click').on('click', function(e){
      e.preventDefault();
      var current = $('.stepper li.active a').attr('href');

      if (current === '#step1') {
        var existId = parseInt($('#cat_existing').val(),10) || 0;
        var newName = ($('#cat_name').val() || '').trim();
        if (!existId && !newName) {
          alert('Válassz főkategóriát a listából, vagy adj meg egy új nevet.');
          return;
        }
        $('.stepper li.active').next().find('a').tab('show');
        return;
      }

      if (current === '#step2') {
        var cat = parseInt($('#sub_parent').val(),10) || parseInt($('#cat_existing').val(),10) || 0;
        if (!cat) { alert('Válaszd ki a főkategóriát.'); return; }
        $('.stepper li.active').next().find('a').tab('show');
        return;
      }

      // egyébként sima lépés
      $('.stepper li.active').next().find('a').tab('show');
    });

    $('#btnPrev').off('click').on('click', function(e){
      e.preventDefault();
      var $a = $('.stepper li.active').prev('li').find('a[data-toggle="tab"]');
      if ($a.length) $a.tab('show');
    });

    // felső „Mentés (csak …)” gombok submitolnak
    $('#saveCategory').off('click').on('click', function(){ $('#form-cat').trigger('submit'); });
    $('#saveSubCategory').off('click').on('click', function(){ $('#form-subcat').trigger('submit'); });

    // törlés gombok
    $('#btnDeleteCategory').off('click').on('click', function(){
      var id = parseInt($('#cat_existing').val(),10);
      if (!id) { alert('Törléshez előbb válassz egy meglévő főkategóriát.'); return; }
      if (confirm('Biztosan törlöd a főkategóriát? Csak akkor lehet, ha nincs alatta tartalom.')) {
        $('#del_cat_id').val(id);
        $('#form-del-cat')[0].submit();
      }
    });
    $('#btnDeleteSubCategory').off('click').on('click', function(){
      var id = parseInt($('#sub_existing').val(),10);
      if (!id) { alert('Törléshez előbb válassz egy meglévő alkategóriát.'); return; }
      if (confirm('Biztosan törlöd az alkategóriát? Csak akkor lehet, ha nincs hozzárendelt katalógus.')) {
        $('#del_sub_id').val(id);
        $('#form-del-sub')[0].submit();
      }
    });

    // STEP1: meglévő főkategória választása -> rejtett mező frissítése, és Step2 parent sync
    $('#cat_existing').on('change', function(){
      var cid = $(this).val();
      if (parseInt(cid,10) > 0) {
        setCatHidden(cid);
        $('#sub_parent').val(cid).trigger('change');
      }
    });

    // STEP2: parent változás -> töltsd az alkategória meglévő listát, hidden cat frissítés
    $('#sub_parent').on('change', function(){
      var cid = $(this).val();
      setCatHidden(cid);
      fillSubExisting(cid, '<?= $wizardSubId ?: 0 ?>');
    }).trigger('change');

    // STEP2: meglévő alkategória választása -> hidden sub frissítése
    $('#sub_existing').on('change', function(){
      var sid = $(this).val();
      setSubHidden( parseInt(sid,10) > 0 ? sid : '' );
    });

    // STEP3: a hidden mezők induló értékei sessionből (ha volt)
    <?php if($wizardCatId): ?> setCatHidden('<?= (int)$wizardCatId ?>'); <?php endif; ?>
    <?php if($wizardSubId): ?> setSubHidden('<?= (int)$wizardSubId ?>'); <?php endif; ?>

    // RTF gombok
    $('[data-cmd="bold"]').on('click', function(){ document.execCommand('bold'); });
    $('#btn-ul').on('click', function(){ document.execCommand('insertUnorderedList'); });

    // Dinamikus mezők
    $('#btnAddGallery').on('click', function(){
      var rows = $('#gallery_wrap .gallery-row').length; if(rows>=4) return;
      $('#gallery_wrap').append(
        '<div class="row gallery-row" style="margin-bottom:8px;">' +
          '<div class="col-sm-8"><input type="file" class="form-control" name="gallery_file[]"></div>' +
          '<div class="col-sm-4"><input type="text" class="form-control" name="gallery_alt[]" placeholder="Cím / alt"></div>' +
        '</div>'
      );
    });
    $('#btnAddPdf').on('click', function(){
      $('#pdf_wrap').append(
        '<div class="row pdf-row" style="margin-bottom:8px;">' +
          '<div class="col-sm-6"><input type="text" class="form-control" name="pdf_name[]" placeholder="Megjelenő név"></div>' +
          '<div class="col-sm-5"><input type="file" class="form-control" name="pdf_file[]"></div>' +
          '<div class="col-sm-1"><button type="button" class="btn btn-link text-danger btnRemovePdf"><i class="fa fa-times"></i></button></div>' +
        '</div>'
      );
    });
    $(document).on('click','.btnRemovePdf', function(){ $(this).closest('.pdf-row').remove(); });

    // URL előnézetek (vizuális)
    $('#cat_name').on('input', function(){
      var s = $(this).val().toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/(^-|-$)/g,'');
      $('#cat_url_preview').text('/'+(s||'minta')+'/');
    });
    $('#sub_name').on('input', function(){
      var s = $(this).val().toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/(^-|-$)/g,'');
      $('#sub_url_preview').text('/…/'+(s||'munkahengerek')+'/');
    });
    $('#cl_title').on('input', function(){
      var s = $(this).val().toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/(^-|-$)/g,'');
      $('#cl_url_preview').text('/…/…/'+(s||'letoltheto-elem')+'/');
    });
  });
})();
</script>
</body>
</html>
