<?php
session_start();
require __DIR__ . '/config/db.php';
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function ir($url): never { header('Location: '.$url); exit; }
function todos(PDO $pdo, string $sql): array { return $pdo->query($sql)->fetchAll(); }
$entidades = [
 'produtos'=>['titulo'=>'Produtos','campos'=>['nome'=>['Nome do produto','text'],'descricao'=>['Descrição','text'],'peso'=>['Peso (kg)','number'],'valor'=>['Valor (R$)','number']]],
 'enderecos'=>['titulo'=>'Endereços','campos'=>['apelido'=>['Identificação','text'],'logradouro'=>['Logradouro','text'],'numero'=>['Número','text'],'bairro'=>['Bairro','text'],'cidade'=>['Cidade','text'],'uf'=>['UF','text'],'cep'=>['CEP','text']]],
 'transportadores'=>['titulo'=>'Transportadores','campos'=>['nome'=>['Nome','text'],'documento'=>['CPF/CNPJ','text'],'telefone'=>['Telefone','text'],'veiculo'=>['Veículo','text'],'placa'=>['Placa','text']]],
];
$pagina=$_GET['pagina'] ?? 'inicio';
if(!in_array($pagina,['inicio','produtos','enderecos','transportadores','fretes','relatorio'],true))$pagina='inicio';
$erro='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try {
  if(!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? ''))throw new RuntimeException('Sessão expirada. Atualize a página.');
  $acao=$_POST['acao']??'';
  if($acao==='salvar' && isset($entidades[$pagina])){
   $campos=array_keys($entidades[$pagina]['campos']);$dados=[];
   foreach($campos as $campo){ $valor=trim((string)($_POST[$campo]??''));if($valor==='')throw new RuntimeException('Preencha todos os campos.');$dados[$campo]=$valor; }
   if($pagina==='produtos' && ((float)$dados['peso']<0||(float)$dados['valor']<0))throw new RuntimeException('Peso e valor não podem ser negativos.');
   $id=filter_var($_POST['id']??0,FILTER_VALIDATE_INT) ?: 0;
   if($id){$set=implode(',',array_map(fn($c)=>"`$c` = ?",$campos));$sql="UPDATE `$pagina` SET $set WHERE id = ?";$val=array_values($dados);$val[]=$id;}
   else {$col=implode(',',array_map(fn($c)=>"`$c`",$campos));$marks=implode(',',array_fill(0,count($campos),'?'));$sql="INSERT INTO `$pagina` ($col) VALUES ($marks)";$val=array_values($dados);}
   $pdo->prepare($sql)->execute($val);ir('?pagina='.$pagina.'&ok=1');
  }
  if($acao==='frete' && $pagina==='fretes'){
   $data=trim((string)($_POST['data_frete']??''));
   $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$data);
   if(!$dt||$dt->format('Y-m-d')!==$data)throw new RuntimeException('Informe uma data válida.');
   $origem=filter_var($_POST['origem_id']??null,FILTER_VALIDATE_INT);
   $destino=filter_var($_POST['destino_id']??null,FILTER_VALIDATE_INT);
   $transp=filter_var($_POST['transportador_id']??null,FILTER_VALIDATE_INT);
   if(!$origem||!$destino||!$transp||$origem===$destino)throw new RuntimeException('Informe origem e destino distintos e um transportador.');
   $ids=$_POST['produto_id']??[];$qtds=$_POST['quantidade']??[];
   if(!is_array($ids)||!is_array($qtds)||count($ids)===0||count($ids)!==count($qtds))throw new RuntimeException('Inclua pelo menos um produto.');
   $itens=[];
   foreach($ids as $i=>$id){$id=filter_var($id,FILTER_VALIDATE_INT);$q=filter_var($qtds[$i],FILTER_VALIDATE_INT);if(!$id||!$q||$q<1)throw new RuntimeException('Produto e quantidade inválidos.');$itens[$id]=($itens[$id]??0)+$q;}
   // Validar referências antes de gravar evita dados inconsistentes.
   foreach([['enderecos',$origem],['enderecos',$destino],['transportadores',$transp]] as [$t,$id]){$s=$pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE id=?");$s->execute([$id]);if(!$s->fetchColumn())throw new RuntimeException('Cadastro relacionado não encontrado.');}
   foreach(array_keys($itens) as $id){$s=$pdo->prepare('SELECT COUNT(*) FROM produtos WHERE id=?');$s->execute([$id]);if(!$s->fetchColumn())throw new RuntimeException('Produto não encontrado.');}
   $pdo->beginTransaction();
   $pdo->prepare('INSERT INTO fretes (data_frete,origem_id,destino_id,transportador_id,observacoes) VALUES (?,?,?,?,?)')->execute([$data,$origem,$destino,$transp,trim((string)($_POST['observacoes']??''))]);
   $frete=(int)$pdo->lastInsertId();$stmt=$pdo->prepare('INSERT INTO frete_produtos (frete_id,produto_id,quantidade) VALUES (?,?,?)');
   foreach($itens as $id=>$q){$stmt->execute([$frete,$id,$q]);}
   $pdo->commit();ir('?pagina=fretes&ok=1');
  }
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$erro=$e instanceof PDOException?'Erro ao salvar. Confira se os dados estão corretos e se os registros não estão em uso.':$e->getMessage();}
}
$_SESSION['csrf'] ??= bin2hex(random_bytes(24));
$token=$_SESSION['csrf'];
// Exportar CSV UTF-8 compatível com Excel. Filtro seguro por data.
if($pagina==='relatorio' && isset($_GET['baixar'])){
 $data=(string)($_GET['data']??'');$dt=DateTimeImmutable::createFromFormat('!Y-m-d',$data);
 if(!$dt||$dt->format('Y-m-d')!==$data){http_response_code(400);exit('Data inválida');}
 $stmt=$pdo->prepare('SELECT f.id, f.data_frete, t.nome AS transportador, o.apelido AS origem, d.apelido AS destino, p.nome AS produto, fp.quantidade, p.valor, (fp.quantidade*p.valor) AS subtotal FROM fretes f JOIN transportadores t ON t.id=f.transportador_id JOIN enderecos o ON o.id=f.origem_id JOIN enderecos d ON d.id=f.destino_id JOIN frete_produtos fp ON fp.frete_id=f.id JOIN produtos p ON p.id=fp.produto_id WHERE f.data_frete=? ORDER BY f.id,p.nome');
 $stmt->execute([$data]);
 header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="fretes-'.$data.'.csv"');
 $saida=fopen('php://output','w');fwrite($saida,"\xEF\xBB\xBF");fputcsv($saida,['Frete','Data','Transportador','Origem','Destino','Produto','Quantidade','Valor unitário','Subtotal'],';');
 foreach($stmt as $r)fputcsv($saida,[$r['id'],$r['data_frete'],$r['transportador'],$r['origem'],$r['destino'],$r['produto'],$r['quantidade'],number_format((float)$r['valor'],2,',',''),number_format((float)$r['subtotal'],2,',','')],';');
 fclose($saida);exit;
}
$produtoList=todos($pdo,'SELECT * FROM produtos ORDER BY nome');
$enderecoList=todos($pdo,'SELECT * FROM enderecos ORDER BY apelido');
$transportadorList=todos($pdo,'SELECT * FROM transportadores ORDER BY nome');
$freteList=todos($pdo,'SELECT f.id,f.data_frete,t.nome AS transportador,o.apelido AS origem,d.apelido AS destino,COUNT(fp.produto_id) AS tipos,SUM(fp.quantidade) AS quantidade FROM fretes f JOIN transportadores t ON t.id=f.transportador_id JOIN enderecos o ON o.id=f.origem_id JOIN enderecos d ON d.id=f.destino_id JOIN frete_produtos fp ON fp.frete_id=f.id GROUP BY f.id,f.data_frete,t.nome,o.apelido,d.apelido ORDER BY f.data_frete DESC,f.id DESC');
$registros=['produtos'=>$produtoList,'enderecos'=>$enderecoList,'transportadores'=>$transportadorList];
$registro=null;
if(isset($entidades[$pagina]) && isset($_GET['editar'])){foreach($registros[$pagina] as $r){if((int)$r['id']===(int)$_GET['editar']){$registro=$r;break;}}}
?><!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>RotaCerta | Gestão logística</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="assets/style.css"></head>
<body><nav class="navbar navbar-expand-lg navbar-dark"><div class="container"><a class="navbar-brand text-white" href="index.php">▣ RotaCerta</a><button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#menu" aria-label="Abrir menu"><span class="navbar-toggler-icon"></span></button><div class="collapse navbar-collapse" id="menu"><div class="navbar-nav ms-auto"><?php foreach(['inicio'=>'Início','produtos'=>'Produtos','enderecos'=>'Endereços','transportadores'=>'Transportadores','fretes'=>'Fretes','relatorio'=>'Relatórios'] as $k=>$v):?><a class="nav-link <?= $pagina===$k?'active':'' ?>" href="?pagina=<?=h($k)?>"><?=h($v)?></a><?php endforeach;?></div></div></div></nav>
<main class="container py-4 py-lg-5">
<?php if(isset($_GET['ok'])):?><div class="alert alert-success" role="status">Operação realizada com sucesso.</div><?php endif;?>
<?php if($erro):?><div class="alert alert-danger" role="alert"><?=h($erro)?></div><?php endif;?>
<?php if($pagina==='inicio'):?>
 <div class="hero mb-4"><div class="small text-uppercase opacity-75">Painel de controle</div><h1 class="fw-bold mt-2">Gestão logística descomplicada</h1><p class="mb-0 opacity-75">Cadastre produtos, endereços e transportadores; organize seus fretes e gere relatórios.</p></div>
 <div class="row g-3 mb-4"><?php foreach([['Produtos',count($produtoList),'produtos'],['Endereços',count($enderecoList),'enderecos'],['Transportadores',count($transportadorList),'transportadores'],['Fretes',count($freteList),'fretes']] as $s):?><div class="col-6 col-lg-3"><div class="card p-4"><div class="small-muted"><?=h($s[0])?></div><div class="stat"><?=h($s[1])?></div><a href="?pagina=<?=h($s[2])?>" class="small text-decoration-none">Abrir módulo →</a></div></div><?php endforeach;?></div>
 <div class="card p-4"><h2 class="h5">Como utilizar</h2><p class="mb-0">1. Cadastre os produtos. 2. Cadastre origem e destino. 3. Cadastre um transportador. 4. Gere um frete com um ou mais produtos. 5. Baixe o relatório por data.</p></div>
<?php elseif(isset($entidades[$pagina])):$cfg=$entidades[$pagina];?>
 <div class="d-flex justify-content-between align-items-center mb-3"><div><div class="small-muted">Cadastros</div><h1 class="h2 fw-bold"><?=h($cfg['titulo'])?></h1></div></div>
 <div class="row g-4"><div class="col-lg-5"><div class="card"><div class="card-header p-3"><?= $registro?'Editar cadastro':'Novo cadastro' ?></div><div class="card-body"><form method="post"><input type="hidden" name="csrf" value="<?=h($token)?>"><input type="hidden" name="acao" value="salvar"><input type="hidden" name="id" value="<?=h($registro['id']??'')?>"><div class="row g-3"><?php foreach($cfg['campos'] as $campo=>$def):?><div class="col-12"><label class="form-label" for="<?=h($campo)?>"><?=h($def[0])?></label><input class="form-control" required id="<?=h($campo)?>" name="<?=h($campo)?>" type="<?=h($def[1])?>" <?= $def[1]==='number'?'min="0" step="0.01"':'' ?> value="<?=h($registro[$campo]??'')?>"></div><?php endforeach;?></div><div class="d-flex gap-2 mt-4"><button class="btn btn-primary" type="submit">Salvar</button><?php if($registro):?><a class="btn btn-outline-secondary" href="?pagina=<?=h($pagina)?>">Cancelar</a><?php endif;?></div></form></div></div></div>
 <div class="col-lg-7"><div class="card"><div class="card-header p-3">Registros cadastrados</div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>ID</th><?php foreach(array_slice($cfg['campos'],0,3) as $campo=>$def):?><th><?=h($def[0])?></th><?php endforeach;?><th>Ação</th></tr></thead><tbody><?php foreach($registros[$pagina] as $r):?><tr><td><?=h($r['id'])?></td><?php foreach(array_slice($cfg['campos'],0,3) as $campo=>$def):?><td><?=h($r[$campo])?></td><?php endforeach;?><td><a class="btn btn-sm btn-outline-primary" href="?pagina=<?=h($pagina)?>&editar=<?=h($r['id'])?>">Editar</a></td></tr><?php endforeach;?><?php if(!$registros[$pagina]):?><tr><td colspan="6" class="text-center text-secondary py-4">Nenhum registro cadastrado.</td></tr><?php endif;?></tbody></table></div></div></div></div>
<?php elseif($pagina==='fretes'):?>
 <div class="small-muted">Movimentação</div><h1 class="h2 fw-bold mb-4">Gerenciar fretes</h1>
 <div class="card mb-4"><div class="card-header p-3">Criar novo frete</div><div class="card-body"><form method="post"><input type="hidden" name="csrf" value="<?=h($token)?>"><input type="hidden" name="acao" value="frete"><div class="row g-3"><div class="col-md-3"><label class="form-label" for="data_frete">Data</label><input class="form-control" id="data_frete" name="data_frete" type="date" value="<?=date('Y-m-d')?>" required></div><div class="col-md-3"><label class="form-label" for="origem_id">Origem</label><select class="form-select" id="origem_id" name="origem_id" required><option value="">Selecione</option><?php foreach($enderecoList as $e):?><option value="<?=h($e['id'])?>"><?=h($e['apelido'].' - '.$e['cidade'])?></option><?php endforeach;?></select></div><div class="col-md-3"><label class="form-label" for="destino_id">Destino</label><select class="form-select" id="destino_id" name="destino_id" required><option value="">Selecione</option><?php foreach($enderecoList as $e):?><option value="<?=h($e['id'])?>"><?=h($e['apelido'].' - '.$e['cidade'])?></option><?php endforeach;?></select></div><div class="col-md-3"><label class="form-label" for="transportador_id">Transportador</label><select class="form-select" id="transportador_id" name="transportador_id" required><option value="">Selecione</option><?php foreach($transportadorList as $t):?><option value="<?=h($t['id'])?>"><?=h($t['nome'])?></option><?php endforeach;?></select></div></div>
 <hr><div class="d-flex justify-content-between align-items-center"><h2 class="h6 fw-bold">Produtos transportados</h2><button class="btn btn-sm btn-outline-primary" type="button" onclick="adicionarProduto()">+ Adicionar produto</button></div>
 <div id="lista-produtos"><div class="row g-2 mb-2 produto-linha"><div class="col-8"><select class="form-select" name="produto_id[]" required><option value="">Selecione um produto</option><?php foreach($produtoList as $p):?><option value="<?=h($p['id'])?>"><?=h($p['nome'])?></option><?php endforeach;?></select></div><div class="col-3"><input class="form-control" aria-label="Quantidade" type="number" min="1" name="quantidade[]" value="1" required></div><div class="col-1"><button class="btn btn-outline-danger w-100" type="button" data-remover-produto aria-label="Remover produto">×</button></div></div></div>
 <template id="modelo-produto"><div class="row g-2 mb-2 produto-linha"><div class="col-8"><select class="form-select" name="produto_id[]" required><option value="">Selecione um produto</option><?php foreach($produtoList as $p):?><option value="<?=h($p['id'])?>"><?=h($p['nome'])?></option><?php endforeach;?></select></div><div class="col-3"><input class="form-control" aria-label="Quantidade" type="number" min="1" name="quantidade[]" value="1" required></div><div class="col-1"><button class="btn btn-outline-danger w-100" type="button" data-remover-produto aria-label="Remover produto">×</button></div></div></template>
 <div class="mt-3"><label class="form-label" for="observacoes">Observações</label><textarea class="form-control" id="observacoes" name="observacoes" maxlength="255" rows="2"></textarea></div><button class="btn btn-primary mt-3" type="submit" <?=(!$produtoList||count($enderecoList)<2||!$transportadorList)?'disabled':''?>>Gerar frete</button><?php if(!$produtoList||count($enderecoList)<2||!$transportadorList):?><div class="small-muted mt-2">Cadastre pelo menos um produto, dois endereços e um transportador.</div><?php endif;?></form></div></div>
 <div class="card"><div class="card-header p-3">Fretes registrados</div><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>#</th><th>Data</th><th>Transportador</th><th>Origem</th><th>Destino</th><th>Tipos</th><th>Qtd.</th></tr></thead><tbody><?php foreach($freteList as $f):?><tr><?php foreach(['id','data_frete','transportador','origem','destino','tipos','quantidade'] as $c):?><td><?=h($f[$c])?></td><?php endforeach;?></tr><?php endforeach;?><?php if(!$freteList):?><tr><td colspan="7" class="text-center text-secondary py-4">Nenhum frete gerado.</td></tr><?php endif;?></tbody></table></div></div>
<?php elseif($pagina==='relatorio'):?>
 <div class="small-muted">Exportação</div><h1 class="h2 fw-bold mb-4">Relatório diário de fretes</h1><div class="card p-4"><p>Selecione uma data para baixar um arquivo CSV com fretes, produtos, quantidades e valores. O arquivo pode ser aberto no Excel ou LibreOffice Calc.</p><form method="get" class="row g-3 align-items-end"><input type="hidden" name="pagina" value="relatorio"><input type="hidden" name="baixar" value="1"><div class="col-md-4"><label class="form-label" for="data">Data do relatório</label><input class="form-control" type="date" id="data" name="data" value="<?=date('Y-m-d')?>" required></div><div class="col-md-3"><button class="btn btn-primary w-100" type="submit">Baixar relatório CSV</button></div></form></div>
<?php endif;?></main><footer class="container border-top pt-3 pb-4 small-muted">RotaCerta · Projeto acadêmico UNIPAR EAD · Fundamentos de Programação para Internet</footer><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="assets/app.js"></script></body></html>
