// Campos dinâmicos de produtos: adicione, remova ou altere as quantidades de um frete.
function adicionarProduto(){
 const modelo=document.getElementById('modelo-produto');
 const lista=document.getElementById('lista-produtos');
 if(!modelo||!lista)return;
 const copia=modelo.content.cloneNode(true);
 lista.appendChild(copia);
}
document.addEventListener('click',event=>{
 if(event.target.closest('[data-remover-produto]')){
  const linhas=document.querySelectorAll('#lista-produtos .produto-linha');
  if(linhas.length>1)event.target.closest('.produto-linha').remove();
 }
});
