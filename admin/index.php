<?php
ini_set('display_errors',1); error_reporting(E_ALL);
require __DIR__.'/../inc/bootstrap.php';
require __DIR__.'/../inc/auth.php';
require_admin();

/* --- Szűrők --- */
$q     = trim($_GET['q']   ?? '');
$catId = (int)($_GET['cat'] ?? 0);
$subId = (int)($_GET['sub'] ?? 0);

/* --- Kategóriák / Alkategóriák a lenyílókhoz --- */
$cats = $pdo->query("SELECT id,name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$subs = $pdo->query("SELECT id,category_id,name FROM subcategories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* JS-hez: alkategóriák csoportosítva szülő szerint */
$subsByCat = [];
foreach ($cats as $c) $subsByCat[$c['id']] = [];
foreach ($subs as $s) $subsByCat[$s['category_id']][] = $s;

/* WHERE + paramok */
$params = []; $types = [];
if ($q !== '') {
  $where = " (c.title LIKE :q OR c.short_text LIKE :q OR c.slug LIKE :q OR cat.name LIKE :q OR COALESCE(sub.name,'') LIKE :q) ";
  $params[':q'] = '%'.$q.'%';  $types[':q'] = PDO::PARAM_STR;
} else {
  $clauses = ['1=1'];
  if ($catId > 0) { $clauses[] = "c.category_id = :cat"; $params[':cat']=$catId; $types[':cat']=PDO::PARAM_INT; }
  if ($subId > 0) { $clauses[] = "c.subcategory_id = :sub"; $params[':sub']=$subId; $types[':sub']=PDO::PARAM_INT; }
  $where = implode(' AND ', $clauses);
}

/* Lapozás */
$perPage = 10;

/* total */
$sqlCnt = "
  SELECT COUNT(*)
  FROM catalogs c
  JOIN categories cat ON cat.id=c.category_id
  LEFT JOIN subcategories sub ON sub.id=c.subcategory_id
  WHERE $where
";
$st = $pdo->prepare($sqlCnt);
foreach ($params as $k=>$v) $st->bindValue($k, $v, $types[$k] ?? PDO::PARAM_STR);
$st->execute();
$total = (int)$st->fetchColumn();

$pages  = max(1, (int)ceil($total / $perPage));
$page   = max(1, min((int)($_GET['p'] ?? 1), $pages));
$offset = ($page - 1) * $perPage;

/* lista */
$sql = "
  SELECT c.id, c.title, c.slug, c.is_new, c.created_at,
         cat.name AS category, COALESCE(sub.name,'') AS subcategory
  FROM catalogs c
  JOIN categories cat ON cat.id=c.category_id
  LEFT JOIN subcategories sub ON sub.id=c.subcategory_id
  WHERE $where
  ORDER BY c.created_at DESC, c.id DESC
  LIMIT :lim OFFSET :off
";
$st = $pdo->prepare($sql);
foreach ($params as $k=>$v) $st->bindValue($k, $v, $types[$k] ?? PDO::PARAM_STR);
$st->bindValue(':lim', $perPage, PDO::PARAM_INT);
$st->bindValue(':off', $offset,  PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

/* lapozó URL */
function page_url($p){
  $qs = $_GET; $qs['p'] = $p; return '?'.http_build_query($qs);
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <title>Dorozs – Admin | Terméklista</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap & Font Awesome -->
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.5/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css">
  <link rel="stylesheet" href="/css/login.css">
  <style>
    .topbar{background:#b30000;color:#fff;padding:8px 0;}
    .topbar .btn{border-color:#fff;color:#fff}
    .table td,.table th{vertical-align:middle!important}
    .margin-top-20{margin-top:20px}.margin-bottom-50{margin-bottom:50px}
		.topbar a:hover{color: #b30000 !important}
  </style>
</head>
<body>

<div class="topbar">
  <div class="container">
    <div class="pull-left">
      <a href="catalog-edit.php" class="btn btn-outline-light btn-sm">
        <i class="fa fa-plus"></i> Új felvétele
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
  <div class="text-center" style="margin:20px 0 10px;">
    <img src="../images/logo.png" alt="Dorozs" style="max-height:36px;">
    <h3 style="margin-top:10px;">Katalógus admin – Terméklista</h3>
  </div>

  <form id="filterForm" method="get" class="row" style="margin-bottom:15px;">
    <div class="col-sm-4">
      <label class="control-label">Főkategória</label>
      <select id="filterCategory" name="cat" class="form-control">
        <option value="">Mind</option>
        <?php foreach($cats as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $catId===(int)$c['id']?'selected':'' ?>>
            <?= htmlspecialchars($c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-4">
      <label class="control-label">Alkategória</label>
      <select id="filterSubcategory" name="sub" class="form-control">
        <option value="">Mind</option>
      </select>
    </div>
    <div class="col-sm-4">
      <label class="control-label">Keresés</label>
      <div class="input-group">
        <input id="productSearch" name="q" type="text" class="form-control"
               placeholder="Név / kategória / alkategória / URL…" value="<?= htmlspecialchars($q) ?>">
        <span class="input-group-btn">
          <button class="btn btn-default" id="productSearchClear" type="button" title="Törlés">
            <i class="fa fa-times"></i>
          </button>
          <button class="btn btn-danger" type="submit" title="Keresés">
            <i class="fa fa-search"></i>
          </button>
        </span>
      </div>
    </div>
    <input type="hidden" name="p" id="pageHidden" value="<?= (int)$page ?>">
  </form>

  <div class="table-responsive">
    <table class="table table-striped table-hover">
      <thead>
        <tr>
          <th>Név</th>
          <th>Főkategória</th>
          <th>Alkategória</th>
          <th class="hidden-xs">URL</th>
          <th class="text-center">Új</th>
          <th class="text-right">Műveletek</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="text-center text-muted">Nincs találat.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['title']) ?></td>
            <td><?= htmlspecialchars($r['category']) ?></td>
            <td><?= htmlspecialchars($r['subcategory']) ?></td>
            <td class="hidden-xs">/<?= htmlspecialchars($r['slug']) ?></td>
            <td class="text-center"><?= $r['is_new'] ? '<span class="label label-danger">ÚJ</span>' : '' ?></td>
            <td class="text-right">
              <a class="btn btn-xs btn-default" href="catalog-edit.php?id=<?= (int)$r['id'] ?>&tab=step3">
                <i class="fa fa-pencil"></i> Szerk.
              </a>
              <a class="btn btn-xs btn-danger" href="catalog-delete.php?id=<?= (int)$r['id'] ?>"
                 onclick="return confirm('Biztos törlöd?');">
                <i class="fa fa-trash"></i> Törlés
              </a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($total > $perPage): ?>
  <nav class="text-center margin-top-20 margin-bottom-50" aria-label="Oldalozás">
    <ul class="pagination">
      <li class="<?= $page<=1?'disabled':'' ?>">
        <a href="<?= $page>1 ? page_url($page-1) : '#' ?>" aria-label="Előző">&laquo;</a>
      </li>
      <?php for ($i=1;$i<=$pages;$i++): ?>
        <li class="<?= $i===$page?'active':'' ?>">
          <?= $i===$page ? '<span>'.$i.'</span>' : '<a href="'.page_url($i).'">'.$i.'</a>' ?>
        </li>
      <?php endfor; ?>
      <li class="<?= $page>=$pages?'disabled':'' ?>">
        <a href="<?= $page<$pages ? page_url($page+1) : '#' ?>" aria-label="Következő">&raquo;</a>
      </li>
    </ul>
  </nav>
  <?php endif; ?>
</div>

<div id="helpOverlay"></div>
<button id="helpFab" class="btn btn-danger btn-lg" title="Súgó"><i class="fa fa-question"></i></button>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.3/jquery.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.5/js/bootstrap.min.js"></script>
<script src="/js/login.js"></script>
<script>
var SUBS_BY_CAT = <?= json_encode($subsByCat, JSON_UNESCAPED_UNICODE) ?>;
(function(){
  function fillSubs(parentId, preselect){
    var $s = $('#filterSubcategory').empty();
    $('<option>').val('').text('Mind').appendTo($s);
    var list = SUBS_BY_CAT[parentId] || [];
    for (var i=0;i<list.length;i++){
      var opt = $('<option>').val(list[i].id).text(list[i].name);
      if (preselect && parseInt(preselect,10)===parseInt(list[i].id,10)) opt.attr('selected','selected');
      $s.append(opt);
    }
  }
  $(function(){
    var initialCat = $('#filterCategory').val() || '';
    var initialSub = '<?= $subId ?: '' ?>';
    if (initialCat) fillSubs(initialCat, initialSub);

    $('#filterCategory').on('change', function(){
      fillSubs(this.value, '');
      $('#pageHidden').val('1');
      $('#filterForm').submit();
    });
    $('#filterSubcategory').on('change', function(){
      $('#pageHidden').val('1');
      $('#filterForm').submit();
    });
    $('#productSearchClear').on('click', function(){
      $('#productSearch').val('');
      $('#pageHidden').val('1');
      $('#filterForm').submit();
    });
  });
})();
</script>
</body>
</html>
