<?php
session_start();
require __DIR__.'/config/db_config.php';
try {
  $db = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) { $db = null; }
if (!$db) { http_response_code(503); echo "Servicio no disponible. Intente mas tarde."; exit; }
$GRUPOS = array('Estudiante'=>'usuarios','Bibliotecario'=>'recepcionista','Administrador'=>'admins');
$users = $db->query('SELECT * FROM users')->fetchAll(PDO::FETCH_ASSOC);
$books = $db->query('SELECT * FROM books')->fetchAll(PDO::FETCH_ASSOC);
$loans = $db->query('SELECT * FROM loans')->fetchAll(PDO::FETCH_ASSOC);
function h($s){ return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8'); }
$u=$_SESSION['u']??null; $msg='';
$_SESSION['fails']=$_SESSION['fails']??0; $_SESSION['block']=$_SESSION['block']??0;
$p=$_POST['p']??$_GET['p']??'inicio';
if($p==='login_go'&&$_SERVER['REQUEST_METHOD']==='POST'){
  if(time()<$_SESSION['block']){ $msg='Bloqueado por 3 intentos. Espere 1 minuto.'; $p='login'; }
  else{ $c=trim($_POST['correo']??''); $k=$_POST['clave']??''; $f=null;
    foreach($users as $x) if($x['correo']===$c) $f=$x;
    if($f&&password_verify($k,$f['clave'])){ $_SESSION['u']=$f; $_SESSION['fails']=0; $u=$f; $p='home'; }
    else{ $_SESSION['fails']++; if($_SESSION['fails']>=3){$_SESSION['block']=time()+60; $msg='Tercer fallo: bloqueado 1 minuto.';} else $msg='Clave incorrecta. Intento '.$_SESSION['fails'].' de 3.'; $p='login'; } }
}
if($p==='logout'){ session_destroy(); session_start(); $u=null; $msg='Sesion cerrada.'; $p='login'; }
if(!$u && in_array($p, array('reservar','validar','devolver','crear','reset'))){ $msg='Necesita una cuenta e iniciar sesion para reservar. Sin cuenta no puede reservar.'; $p='login'; }
if($u&&$_SERVER['REQUEST_METHOD']==='POST'){
  if($p==='reservar'&&$u['rol']==='Estudiante'){ $bid=(int)($_POST['book_id']??0);
    $b=$db->query("SELECT * FROM books WHERE id=$bid")->fetch(); if($b&&$b['stock']>0){ $folio='R-'.rand(1042,9999); $db->exec("INSERT INTO loans(user_id,book_id,estado,folio) VALUES({$u['id']},$bid,'Activa','$folio')"); $db->exec("UPDATE books SET stock=stock-1 WHERE id=$bid"); $msg="Reserva $folio creada. Retiro en 48 h."; } else $msg='Sin stock. Queda en cola.';
    $p='home'; }
  if(($p==='validar'||$p==='devolver')&&$u['rol']==='Bibliotecario'){ $lid=(int)($_POST['loan_id']??0); $est=($p==='validar'?'Aprobado':'Devuelto');
    $l=$db->query("SELECT * FROM loans WHERE id=$lid")->fetch(); $db->exec("UPDATE loans SET estado='$est' WHERE id=$lid"); if($p==='devolver'&&$l) $db->exec("UPDATE books SET stock=stock+1 WHERE id={$l['book_id']}");
    $msg=$p==='devolver'?'Devolucion registrada y stock repuesto.':'Prestamo actualizado.'; $p='home'; }
  if($p==='libro_stock'&&in_array($u['rol'],array('Bibliotecario','Administrador'))){ $bid=(int)($_POST['book_id']??0); $st=(int)($_POST['stock']??0);
    $db->exec("UPDATE books SET stock=$st WHERE id=$bid");
    $msg="Stock actualizado."; $p='home'; }
  if($p==='libro_add'&&in_array($u['rol'],array('Bibliotecario','Administrador'))){ $t=trim($_POST['titulo']??''); $a=trim($_POST['autor']??''); $st=(int)($_POST['stock']??1);
    if($t!==''){ $db->prepare('INSERT INTO books(titulo,autor,stock) VALUES(?,?,?)')->execute([$t,$a,$st]); $msg="Libro agregado."; }
    $p='home'; }
  if($p==='crear'&&$u['rol']==='Administrador'){ $n=trim($_POST['nombre']??''); $c=trim($_POST['correo']??''); $r=$_POST['rol']??'Estudiante'; $t=$_POST['tmp']??'';
    if(strlen($t)<8||!preg_match('/[A-Z]/',$t)||!preg_match('/[0-9]/',$t)||!preg_match('/[\*\.\-]/',$t)) $msg='Temporal no cumple 4 reglas.';
    else{ $g=$GRUPOS[$r]??'usuarios'; try{ $db->prepare('INSERT INTO users(nombre,correo,clave,rol,grupo) VALUES(?,?,?,?,?)')->execute([$n,$c,password_hash($t,PASSWORD_BCRYPT),$r,$g]); $msg="Usuario $c creado en grupo $g."; }catch(Exception $e){ $msg='Correo ya existe.'; } }
    $p='home'; }
  if($p==='reset'&&$u['rol']==='Administrador'){ $c=trim($_POST['correo']??'');
    $db->prepare('UPDATE users SET clave=? WHERE correo=?')->execute([password_hash('Inacap2026*',PASSWORD_BCRYPT),$c]);
    $msg="Clave de $c restablecida."; $p='home'; }
  if($p==='admin_mod'&&$u['rol']==='Administrador'){ $id=(int)($_POST['uid']??0); $nr=$_POST['rol']??'Estudiante'; $ng=$GRUPOS[$nr]??'usuarios'; $nn=trim($_POST['nombre']??''); $nc=trim($_POST['correo']??''); $np=trim($_POST['nueva']??'');
    if($np!==''&&(strlen($np)<8||!preg_match('/[A-Z]/',$np)||!preg_match('/[0-9]/',$np)||!preg_match('/[\*\.\-]/',$np))){ $msg='Nueva clave no cumple 4 reglas.'; }
    else{ if($np!=='') $db->prepare('UPDATE users SET nombre=?,correo=?,rol=?,grupo=?,clave=? WHERE id=?')->execute([$nn,$nc,$nr,$ng,password_hash($np,PASSWORD_BCRYPT),$id]); else $db->prepare('UPDATE users SET nombre=?,correo=?,rol=?,grupo=? WHERE id=?')->execute([$nn,$nc,$nr,$ng,$id]);
      $msg="Usuario $id modificado con correo y clave. Admin puede modificar todos."; }
    $p='home'; }
  if($p==='admin_del'&&$u['rol']==='Administrador'){ $id=(int)($_POST['uid']??0);
    if($id==$u['id']){ $msg='No puede eliminarse a si mismo.'; }
    else{ $db->exec("DELETE FROM users WHERE id=$id");
      $msg="Usuario $id eliminado."; }
    $p='home'; }
  if($p==='recep_mod'&&$u['rol']==='Bibliotecario'){ $id=(int)($_POST['uid']??0); $nn=trim($_POST['nombre']??'');
    $ok=false; foreach($users as $x) if($x['id']==$id&&($x['grupo']==='usuarios'||$x['rol']==='Estudiante')) $ok=true;
    if(!$ok){ $msg='Recepcion solo puede modificar usuarios normales.'; }
    else{ $db->prepare('UPDATE users SET nombre=?, clave=? WHERE id=?')->execute([$nn,password_hash('Inacap2026*',PASSWORD_BCRYPT),$id]);
      $msg="Usuario normal $id modificado y clave restablecida por recepcion."; }
    $p='home'; }
}
$users=$db->query('SELECT * FROM users')->fetchAll(PDO::FETCH_ASSOC); $books=$db->query('SELECT * FROM books')->fetchAll(PDO::FETCH_ASSOC); $loans=$db->query('SELECT * FROM loans')->fetchAll(PDO::FETCH_ASSOC);
$bookName=function($id) use($books){ foreach($books as $b) if($b['id']==$id) return $b['titulo']; return ''; };
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Biblioteca INACAP - Sistema de Gestion</title>
<link rel="stylesheet" href="css/estilos.css"></head><body>
<header><div><a href="?p=inicio" style="text-decoration:none"><b>Biblioteca INACAP</b></a> | <a style="color:#FADBD8" href="?p=inicio">Inicio</a> <?= $u?' | <a style="color:#FADBD8" href="?p=home">Mi panel</a>':'' ?></div><div><?= $u?h($u['rol']).' | <a style=color:#fff href=?p=logout>Salir</a>':'<a style=color:#fff href=?p=login>Ingresar</a>' ?></div></header>
<main>
<?php if($msg): ?><div class="card <?= (str_contains($msg,'incorrecta')||str_contains($msg,'Bloq')||str_contains($msg,'no cumple'))?'err':'ok' ?>"><?= h($msg) ?></div><?php endif; ?>
<?php if($p==='inicio' || $p==='login' && false): ?>
<?php endif; ?>
<?php if($p==='inicio'): $colores=array('#FADBD8','#D6EAF8','#D5F5E3','#FEF9E7','#E8DAEF'); $q=trim($_GET['q']??''); ?>
<div class="card" style="background:#FFF7F0"><h2>Biblioteca Digital INACAP - Busca, reserva y retira en 48 h</h2>
<form method="get" class="buscador"><input type="hidden" name="p" value="inicio"><input name="q" placeholder="¿Que libro buscas hoy? Ej. calculo, redes, anatomia..." value="<?= h($q) ?>"><button>Buscar libros</button></form>
<div class="grid"><?php $IMGS=array(1=>'imagenes_libros/libro01_calculo.jpg',2=>'imagenes_libros/libro02_redes.jpg',3=>'imagenes_libros/libro03_netter.jpg',4=>'imagenes_libros/libro04_suelos.jpg',5=>'imagenes_libros/libro05_brunner.jpg',6=>'imagenes_libros/libro06_fisica.jpg',7=>'imagenes_libros/libro07_python.jpg',8=>'imagenes_libros/libro08_bd.jpg',9=>'imagenes_libros/libro09_gray.jpg',10=>'imagenes_libros/libro10_calculo2.jpg',11=>'imagenes_libros/libro11_ccna.jpg',12=>'imagenes_libros/libro12_farmaco.jpg'); $i=0; foreach($books as $b): if($q&&stripos($b['titulo'].$b['autor'],$q)===false) continue; $c=$colores[$i++%5]; $img=$IMGS[$b['id']]??'imagenes_libros/libro01_calculo.jpg'; ?>
<div class="libro"><div class="portada"><img src="<?= h($img) ?>" alt="<?= h($b['titulo']) ?>" loading="lazy"></div>
<div class="cuerpo"><b><?= h($b['titulo']) ?></b><br><span><?= h($b['autor']) ?></span><br>
<span class="badge <?= $b['stock']>0?'ok2':'mal' ?>"><?= $b['stock']>0?'Disponible ('.$b['stock'].')':'Prestado' ?></span><br><br>
<?php if(!$u): ?><a href="?p=login">Inicie sesion para reservar</a><?php elseif($u['rol']==='Estudiante'&&$b['stock']>0): ?><form method="post" action="?p=reservar"><input type="hidden" name="book_id" value="<?= $b['id'] ?>"><button>Reservar ahora</button></form><?php elseif($u['rol']==='Estudiante'): ?>En cola<?php else: ?><a href="?p=home">Ver en Mi panel</a><?php endif; ?></div></div><?php endforeach; ?></div>
</div>
<?php endif; ?>
<?php if(!$u && $p!=='catalogo' && $p!=='inicio'): ?><div class="card"><h2>Acceso diferenciado</h2>
<form method="post" action="?p=login_go"><label>Correo</label><input name="correo" placeholder="correo@inacapmail.cl"><label>Clave</label><input name="clave" type="password"><button>Ingresar</button></form>
</div><?php endif; ?>
<?php if($p==='catalogo'): $q=trim($_GET['q']??''); $colores=array('#FADBD8','#D6EAF8','#D5F5E3','#FEF9E7','#E8DAEF'); ?>
<div class="card"><h2>BuscaLibros INACAP - Explora sin cuenta</h2>
<form method="get" class="buscador"><input type="hidden" name="p" value="catalogo"><input name="q" placeholder="Buscar por titulo, autor o carrera: calculo, redes, anatomia..." value="<?= h($q) ?>"><button>Buscar</button></form>
<div class="grid">
<?php $IMGS2=array(1=>'imagenes_libros/libro01_calculo.jpg',2=>'imagenes_libros/libro02_redes.jpg',3=>'imagenes_libros/libro03_netter.jpg',4=>'imagenes_libros/libro04_suelos.jpg',5=>'imagenes_libros/libro05_brunner.jpg',6=>'imagenes_libros/libro06_fisica.jpg',7=>'imagenes_libros/libro07_python.jpg',8=>'imagenes_libros/libro08_bd.jpg',9=>'imagenes_libros/libro09_gray.jpg',10=>'imagenes_libros/libro10_calculo2.jpg',11=>'imagenes_libros/libro11_ccna.jpg',12=>'imagenes_libros/libro12_farmaco.jpg'); $i=0; foreach($books as $b): if($q&&stripos($b['titulo'].$b['autor'],$q)===false) continue; $c=$colores[$i++ % count($colores)]; $img=$IMGS2[$b['id']]??'imagenes_libros/libro01_calculo.jpg'; ?>
<div class="libro"><div class="portada"><img src="<?= h($img) ?>" alt="<?= h($b['titulo']) ?>" loading="lazy"></div>
<div class="cuerpo"><b><?= h($b['titulo']) ?></b><br><span><?= h($b['autor']) ?></span><br>
<span class="badge <?= $b['stock']>0?'ok2':'mal' ?>"><?= $b['stock']>0?'Disponible ('.$b['stock'].')':'Prestado' ?></span><br><br>
<?php if(!$u): ?><a href="?p=login">Inicie sesion para reservar</a><?php elseif($u['rol']==='Estudiante'&&$b['stock']>0): ?><form method="post" action="?p=reservar"><input type="hidden" name="book_id" value="<?= $b['id'] ?>"><button>Reservar ahora</button></form><?php elseif($u['rol']==='Estudiante'): ?>En cola<?php else: ?>Solo Estudiantes<?php endif; ?></div></div><?php endforeach; ?></div></div>
<?php endif; ?>
<?php if($u && $p!=='catalogo'): ?><div class="card"><h2>Hola, <?= h($u['nombre']) ?> (<?= h($u['rol']) ?>)</h2>
<script src="js/app.js"></script>
<?php if($u['rol']==='Estudiante'): $q=trim($_GET['q']??''); ?>
<div class="tabs"><button class="sel" onclick="tab('t-buscar')">Buscar libros</button><button onclick="tab('t-mis')">Mis reservas</button></div>
<div class="tabpane sel" id="t-buscar">
<form method="get"><input type="hidden" name="p" value="home"><input name="q" placeholder="Buscar titulo o autor" value="<?= h($q) ?>"><button>Buscar</button></form>
<table><tr><th>Titulo</th><th>Stock</th><th>Accion</th></tr>
<?php foreach($books as $b): if($q&&stripos($b['titulo'].$b['autor'],$q)===false) continue; ?>
<tr><td><?= h($b['titulo']) ?> (<?= h($b['autor']) ?>)</td><td><?= $b['stock'] ?></td>
<td><?php if($b['stock']>0): ?><form method="post" action="?p=reservar"><input type="hidden" name="book_id" value="<?= $b['id'] ?>"><button>Reservar</button></form><?php else: ?>En cola<?php endif; ?></td></tr><?php endforeach; ?></table></div>
<div class="tabpane" id="t-mis"><h3>Mis reservas</h3><table><tr><th>Folio</th><th>Libro</th><th>Estado</th></tr>
<?php foreach($loans as $r): if($r['user_id']!=$u['id']) continue; ?><tr><td><?= h($r['folio']) ?></td><td><?= h($bookName($r['book_id'])) ?></td><td><?= h($r['estado']) ?></td></tr><?php endforeach; ?></table></div>
<?php elseif($u['rol']==='Bibliotecario'): ?>
<div class="tabs"><button class="sel" onclick="tab('t-cola')">Prestamos</button><button onclick="tab('t-lib')">Libros</button><button onclick="tab('t-usu')">Usuarios</button></div>
<div class="tabpane sel" id="t-cola"><h3>Cola por validar</h3>
<table><tr><th>Folio</th><th>Libro</th><th>Estado</th><th>Accion</th></tr>
<?php foreach($loans as $r): if($r['estado']==='Devuelto') continue; ?><tr><td><?= h($r['folio']) ?></td><td><?= h($bookName($r['book_id'])) ?></td><td><?= h($r['estado']) ?></td>
<td><?php if($r['estado']==='Activa'): ?><form method="post" action="?p=validar" style="display:inline"><input type="hidden" name="loan_id" value="<?= $r['id'] ?>"><button>Aprobar</button></form><?php endif; ?>
<form method="post" action="?p=devolver" style="display:inline"><input type="hidden" name="loan_id" value="<?= $r['id'] ?>"><button>Devolver</button></form></td></tr><?php endforeach; ?></table>
<h3>Historial devuelto</h3>
<table><tr><th>Folio</th><th>Libro</th><th>Estado</th></tr>
<?php foreach($loans as $r): if($r['estado']!=='Devuelto') continue; ?><tr><td><?= h($r['folio']) ?></td><td><?= h($bookName($r['book_id'])) ?></td><td><?= h($r['estado']) ?></td></tr><?php endforeach; ?></table>
</div><div class="tabpane" id="t-lib"><h3>Gestionar libros: pausar y reponer stock</h3>
<?php foreach($books as $b): ?>
<div class="hrow"><form method="post" action="?p=libro_stock" style="display:contents"><input type="hidden" name="book_id" value="<?= $b['id'] ?>">
<div><b><?= h($b['titulo']) ?></b><br><?= h($b['autor']) ?></div>
<div><label>Stock</label><input name="stock" type="number" min="0" value="<?= $b['stock'] ?>"></div>
<div class="acc"><button>Guardar stock</button></div></form></div><?php endforeach; ?>
<form method="post" action="?p=libro_add"><div class="hrow"><div><label>Titulo</label><input name="titulo" placeholder="Nuevo libro"></div><div><label>Autor</label><input name="autor" placeholder="Autor"></div><div><label>Stock</label><input name="stock" type="number" value="1"></div><div class="acc"><button>Agregar libro</button></div></div></form>
</div><div class="tabpane" id="t-usu"><h3>Modificar solo usuarios normales</h3>
<?php foreach($users as $x): if(($x['grupo']??'')!=='usuarios'&&$x['rol']!=='Estudiante') continue; ?>
<div class="hrow">
<form method="post" action="?p=recep_mod" style="display:contents"><input type="hidden" name="uid" value="<?= $x['id'] ?>">
<div><label>Nombre</label><input name="nombre" value="<?= h($x['nombre']) ?>"></div>
<div><label>Correo</label><input value="<?= h($x['correo']) ?>" disabled></div>
<div class="acc"><button>Guardar y restablecer</button></div></form>
</div><?php endforeach; ?>
<?php else: ?>
<div class="tabs"><button class="sel" onclick="tab('t-ausu')">Usuarios</button><button onclick="tab('t-acrear')">Crear</button><button onclick="tab('t-alib')">Libros</button><button onclick="tab('t-arep')">Reporte</button></div>
<div class="tabpane sel" id="t-ausu"><h3>Modificar todos: admins, recepcionista y usuarios</h3>
<?php foreach($users as $x): ?>
<div class="hrow">
<form method="post" action="?p=admin_mod" style="display:contents"><input type="hidden" name="uid" value="<?= $x['id'] ?>">
<div><label>Nombre</label><input name="nombre" value="<?= h($x['nombre']) ?>"></div>
<div><label>Correo</label><input name="correo" value="<?= h($x['correo']) ?>"></div>
<div><label>Nueva clave</label><input name="nueva" placeholder="vacia = no cambia"></div>
<div><label>Rol (<?= h($x['grupo']??'') ?>)</label><select name="rol"><option <?= $x['rol']==='Estudiante'?'selected':'' ?>>Estudiante</option><option <?= $x['rol']==='Bibliotecario'?'selected':'' ?>>Bibliotecario</option><option <?= $x['rol']==='Administrador'?'selected':'' ?>>Administrador</option></select></div>
<div class="acc"><button>Guardar</button></div></form>
<div class="acc"><form method="post" action="?p=admin_del" onsubmit="return confirm('Eliminar <?= h($x['correo']) ?>?')"><input type="hidden" name="uid" value="<?= $x['id'] ?>"><button>Eliminar</button></form></div>
</div><?php endforeach; ?>
</div><div class="tabpane" id="t-acrear">
<form method="post" action="?p=crear"><input name="nombre" placeholder="Nombre"><input name="correo" placeholder="correo@inacapmail.cl"><select name="rol"><option>Estudiante</option><option>Bibliotecario</option><option>Administrador</option></select><input name="tmp" value="Inacap2026*"><button>Crear usuario</button></form>
<form method="post" action="?p=reset"><input name="correo" placeholder="correo a restablecer"><button>Restablecer</button></form></div>
<div class="tabpane" id="t-alib"><h3>Gestionar libros: pausar y reponer stock</h3>
<?php foreach($books as $b): ?>
<div class="hrow"><form method="post" action="?p=libro_stock" style="display:contents"><input type="hidden" name="book_id" value="<?= $b['id'] ?>">
<div><b><?= h($b['titulo']) ?></b></div>
<div><label>Stock</label><input name="stock" type="number" min="0" value="<?= $b['stock'] ?>"></div>
<div class="acc"><button>Guardar</button></div></form></div><?php endforeach; ?></div>
<div class="tabpane" id="t-arep"><p>Prestamos totales: <?= count($loans) ?>. Morosidad actual 4,2%.</p></div>
<?php endif; ?></div><?php endif; ?>
</main></body></html>
