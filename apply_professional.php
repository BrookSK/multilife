<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

// Página pública - não requer autenticação
$user = auth_user();
$isPublic = ($user === null);

if ($isPublic) {
    // Renderizar header público sem menu
    echo '<!doctype html>';
    echo '<html lang="pt-BR">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Candidatura de Profissional - MultiLife Care</title>';
    echo '<style>';
    echo "@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');";
    echo ':root{--background:216 33% 97%;--foreground:210 36% 17%;--card:0 0% 100%;--card-foreground:210 36% 17%;--primary:180 65% 46%;--primary-foreground:0 0% 100%;--primary-dark:180 71% 36%;--muted:216 33% 97%;--muted-foreground:216 18% 61%;--border:216 20% 90%;--input:216 20% 90%;--ring:180 65% 46%;--radius:0.625rem;--shadow-card:0 1px 3px 0 rgba(0,0,0,.06),0 1px 2px -1px rgba(0,0,0,.06);--shadow-elevated:0 10px 25px -5px rgba(0,0,0,.08),0 8px 10px -6px rgba(0,0,0,.04);}';
    echo '*{box-sizing:border-box;border-color:hsl(var(--border))}';
    echo 'html,body{height:100%}';
    echo 'body{margin:0;font-family:Inter,ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial;min-height:100vh;color:hsl(var(--foreground));background:hsl(var(--background));}';
    echo 'input,select,textarea{font-family:inherit}';
    echo 'input[type="text"],input[type="email"],input[type="password"],input[type="number"],select,textarea{width:100%;border-radius:10px;border:1px solid hsl(var(--input));background:hsla(var(--muted)/.50);color:hsl(var(--foreground));padding:10px 12px;outline:none;font-size:14px;transition:background .15s ease,box-shadow .15s ease,border-color .15s ease}';
    echo 'textarea{min-height:96px;resize:vertical}';
    echo 'input:focus,select:focus,textarea:focus{background:hsl(var(--card));border-color:hsla(var(--ring)/.55);box-shadow:0 0 0 4px hsla(var(--ring)/.15)}';
    echo '::placeholder{color:hsl(var(--muted-foreground))}';
    echo 'label{display:grid;gap:7px;font-size:13px;font-weight:600;color:hsl(var(--foreground))}';
    echo 'form{display:grid;gap:14px}';
    echo '.formSection{padding:18px;border-radius:12px;background:hsla(var(--muted)/.25);border:1px solid hsl(var(--border));margin-bottom:14px}';
    echo '.formSectionTitle{font-size:15px;font-weight:800;color:hsl(var(--foreground));margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid hsl(var(--border))}';
    echo '.card{background:hsl(var(--card));border:1px solid hsl(var(--border));box-shadow:var(--shadow-elevated);border-radius:calc(var(--radius) + 6px);padding:18px;color:hsl(var(--card-foreground))}';
    echo '.grid{display:grid;grid-template-columns:repeat(12,1fr);gap:14px}';
    echo '.col6{grid-column:span 6}';
    echo '.btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 12px;border-radius:10px;border:1px solid hsl(var(--border));background:hsl(var(--card));color:hsl(var(--foreground));font-weight:600;font-size:13px;box-shadow:var(--shadow-card);transition:box-shadow .15s ease,transform .06s ease,background .15s ease;text-decoration:none}';
    echo '.btn:hover{box-shadow:0 4px 12px 0 rgba(0,0,0,.08),0 2px 4px -1px rgba(0,0,0,.06);text-decoration:none}';
    echo '.btn:active{transform:translateY(1px)}';
    echo '.btnPrimary{border-color:transparent;background:hsl(var(--primary));color:hsl(var(--primary-foreground))}';
    echo '.btnPrimary:hover{background:hsl(var(--primary-dark))}';
    echo '@media(max-width:860px){.col6{grid-column:span 12}}';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    echo '<div style="max-width:1100px;margin:0 auto;padding:24px">';
} else {
    view_header('Candidatura de Profissional');
}

echo '<div class="card">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Candidatura de Profissional - MultiLife Care</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Preencha seus dados para avaliação. Após aprovação, você receberá acesso ao sistema.</div>';
echo '</div>';
if (!$isPublic) {
    echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
    echo '<a class="btn" href="/professional_applications_list.php">Voltar</a>';
    echo '</div>';
}
echo '</div>';

echo '<div style="height:14px"></div>';

// Exibir mensagens flash
$flash = flash_get();
if ($flash) {
    $bgColor = $flash['type'] === 'success' ? 'hsl(142,76%,36%)' : 'hsl(0,84%,60%)';
    $textColor = '#fff';
    echo '<div style="padding:12px 16px;border-radius:10px;background:' . $bgColor . ';color:' . $textColor . ';font-weight:600;margin-bottom:14px">';
    echo h($flash['message']);
    echo '</div>';
}

echo '<form method="post" action="/apply_professional_post.php" style="display:grid;gap:12px">';

echo '<div class="grid">';

echo '<div class="col6">';
echo '<label>Nome completo<input name="full_name" required maxlength="160" placeholder="Nome completo"></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>E-mail<input type="email" name="email" required maxlength="190" placeholder="email@empresa.com"></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Telefone/WhatsApp<input name="phone" required maxlength="30" placeholder="5511999999999"></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Cidades de atuação<textarea name="cities_of_operation" rows="2" placeholder="Ex: São Paulo, Guarulhos"></textarea></label>';
echo '</div>';

echo '</div>';

echo '<div style="font-weight:900;margin-top:6px">Identificação</div>';

echo '<div class="grid">';
echo '<div class="col6"><label>Estado civil<select name="marital_status"><option value="">Selecione...</option><option value="Solteiro(a)">Solteiro(a)</option><option value="Casado(a)">Casado(a)</option><option value="Divorciado(a)">Divorciado(a)</option><option value="Viúvo(a)">Viúvo(a)</option><option value="União Estável">União Estável</option></select></label></div>';
echo '<div class="col6"><label>Sexo<select name="sex"><option value="">Selecione...</option><option value="Masculino">Masculino</option><option value="Feminino">Feminino</option><option value="Outro">Outro</option></select></label></div>';
echo '<div class="col6"><label>Religião<input name="religion" maxlength="60"></label></div>';
echo '<div class="col6"><label>Naturalidade<input name="birthplace" maxlength="120"></label></div>';
echo '<div class="col6"><label>Nacionalidade<input name="nationality" maxlength="80"></label></div>';
echo '<div class="col6"><label>Escolaridade<select name="education_level"><option value="">Selecione...</option><option value="Ensino Fundamental">Ensino Fundamental</option><option value="Ensino Médio">Ensino Médio</option><option value="Ensino Superior">Ensino Superior</option><option value="Pós-graduação">Pós-graduação</option><option value="Mestrado">Mestrado</option><option value="Doutorado">Doutorado</option></select></label></div>';
echo '</div>';

echo '<div style="font-weight:900;margin-top:6px">Endereço</div>';

echo '<div class="grid">';
echo '<div class="col6"><label>Logradouro<input name="address_street" maxlength="160"></label></div>';
echo '<div class="col6"><label>Número<input name="address_number" maxlength="20"></label></div>';
echo '<div class="col6"><label>Complemento<input name="address_complement" maxlength="80"></label></div>';
echo '<div class="col6"><label>Bairro<input name="address_neighborhood" maxlength="80"></label></div>';
echo '<div class="col6"><label>UF<select name="address_state" id="address_state"><option value="">Selecione...</option><option value="AC">AC - Acre</option><option value="AL">AL - Alagoas</option><option value="AP">AP - Amapá</option><option value="AM">AM - Amazonas</option><option value="BA">BA - Bahia</option><option value="CE">CE - Ceará</option><option value="DF">DF - Distrito Federal</option><option value="ES">ES - Espírito Santo</option><option value="GO">GO - Goiás</option><option value="MA">MA - Maranhão</option><option value="MT">MT - Mato Grosso</option><option value="MS">MS - Mato Grosso do Sul</option><option value="MG">MG - Minas Gerais</option><option value="PA">PA - Pará</option><option value="PB">PB - Paraíba</option><option value="PR">PR - Paraná</option><option value="PE">PE - Pernambuco</option><option value="PI">PI - Piauí</option><option value="RJ">RJ - Rio de Janeiro</option><option value="RN">RN - Rio Grande do Norte</option><option value="RS">RS - Rio Grande do Sul</option><option value="RO">RO - Rondônia</option><option value="RR">RR - Roraima</option><option value="SC">SC - Santa Catarina</option><option value="SP">SP - São Paulo</option><option value="SE">SE - Sergipe</option><option value="TO">TO - Tocantins</option></select></label></div>';
echo '<div class="col6"><label>Cidade<select name="address_city" id="address_city"><option value="">Selecione o estado primeiro...</option></select></label></div>';
echo '<div class="col6"><label>CEP<input name="address_zip" maxlength="12" placeholder="00000-000"></label></div>';
echo '</div>';

echo '<div style="font-weight:900;margin-top:6px">Documentos</div>';

echo '<div class="grid">';
echo '<div class="col6"><label>RG<input name="rg" maxlength="30"></label></div>';
echo '<div class="col6"><label>Sigla do Conselho<select name="council_abbr"><option value="">Selecione...</option><option value="COREN">COREN - Enfermagem</option><option value="CRM">CRM - Medicina</option><option value="CRF">CRF - Farmácia</option><option value="CREFITO">CREFITO - Fisioterapia/Terapia Ocupacional</option><option value="CRN">CRN - Nutrição</option><option value="CREFONO">CREFONO - Fonoaudiologia</option><option value="CRP">CRP - Psicologia</option><option value="CRBM">CRBM - Biomedicina</option><option value="Outro">Outro</option></select></label></div>';
echo '<div class="col6"><label>Número do Conselho<input name="council_number" maxlength="30"></label></div>';
echo '<div class="col6"><label>UF do Conselho<select name="council_state"><option value="">Selecione...</option><option value="AC">AC - Acre</option><option value="AL">AL - Alagoas</option><option value="AP">AP - Amapá</option><option value="AM">AM - Amazonas</option><option value="BA">BA - Bahia</option><option value="CE">CE - Ceará</option><option value="DF">DF - Distrito Federal</option><option value="ES">ES - Espírito Santo</option><option value="GO">GO - Goiás</option><option value="MA">MA - Maranhão</option><option value="MT">MT - Mato Grosso</option><option value="MS">MS - Mato Grosso do Sul</option><option value="MG">MG - Minas Gerais</option><option value="PA">PA - Pará</option><option value="PB">PB - Paraíba</option><option value="PR">PR - Paraná</option><option value="PE">PE - Pernambuco</option><option value="PI">PI - Piauí</option><option value="RJ">RJ - Rio de Janeiro</option><option value="RN">RN - Rio Grande do Norte</option><option value="RS">RS - Rio Grande do Sul</option><option value="RO">RO - Rondônia</option><option value="RR">RR - Roraima</option><option value="SC">SC - Santa Catarina</option><option value="SP">SP - São Paulo</option><option value="SE">SE - Sergipe</option><option value="TO">TO - Tocantins</option></select></label></div>';
echo '</div>';

echo '<div style="font-weight:900;margin-top:6px">Dados bancários</div>';

echo '<div class="grid">';
echo '<div class="col6"><label>Banco<input name="bank_name" maxlength="80"></label></div>';
echo '<div class="col6"><label>Agência<input name="bank_agency" maxlength="20"></label></div>';
echo '<div class="col6"><label>Conta<input name="bank_account" maxlength="30"></label></div>';
echo '<div class="col6"><label>Tipo de conta<select name="bank_account_type"><option value="">Selecione...</option><option value="Corrente">Corrente</option><option value="Poupança">Poupança</option><option value="Salário">Salário</option></select></label></div>';
echo '<div class="col6"><label>Titular<input name="bank_account_holder" maxlength="160"></label></div>';
echo '<div class="col6"><label>CPF do titular<input name="bank_account_holder_cpf" maxlength="20"></label></div>';
echo '<div class="col6"><label>PIX<input name="pix_key" maxlength="120"></label></div>';
echo '<div class="col6"><label>Titular do PIX<input name="pix_holder" maxlength="160"></label></div>';
echo '</div>';

echo '<div style="font-weight:900;margin-top:6px">Informações técnicas</div>';

echo '<label>Experiência em home care<textarea name="home_care_experience" rows="3"></textarea></label>';

echo '<div class="grid">';
echo '<div class="col6"><label>Tempo de atuação<input name="years_of_experience" maxlength="40" placeholder="Ex: 5 anos"></label></div>';
echo '<div class="col6"><label>Especializações/Pós<textarea name="specializations" rows="2"></textarea></label></div>';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin-top:6px">';
if ($isPublic) {
    echo '<button class="btn btnPrimary" type="submit" style="font-size:15px;padding:12px 24px">Enviar Candidatura</button>';
} else {
    echo '<a class="btn" href="/professional_applications_list.php">Cancelar</a>';
    echo '<button class="btn btnPrimary" type="submit">Enviar candidatura</button>';
}
echo '</div>';

echo '</form>';

echo '</div>';

// JavaScript para popular cidades baseado no estado
echo '<script>';
echo 'const cidadesPorEstado = {';
echo '"SP": ["São Paulo","Guarulhos","Campinas","São Bernardo do Campo","Santo André","Osasco","São José dos Campos","Ribeirão Preto","Sorocaba","Santos","Mauá","São José do Rio Preto","Diadema","Jundiaí","Piracicaba","Carapicuíba","Bauru","Itaquaquecetuba","São Vicente","Franca","Praia Grande","Limeira","Suzano","Taboão da Serra","Sumaré","Americana","Araraquara","Jacareí","Indaiatuba","Taubaté","Embu das Artes","Cotia","Marília","Presidente Prudente","Hortolândia","Sertãozinho","Outra"],';
echo '"RJ": ["Rio de Janeiro","São Gonçalo","Duque de Caxias","Nova Iguaçu","Niterói","Belford Roxo","Campos dos Goytacazes","São João de Meriti","Petrópolis","Volta Redonda","Magé","Itaboraí","Macaé","Cabo Frio","Nova Friburgo","Barra Mansa","Angra dos Reis","Teresópolis","Mesquita","Nilópolis","Outra"],';
echo '"MG": ["Belo Horizonte","Uberlândia","Contagem","Juiz de Fora","Betim","Montes Claros","Ribeirão das Neves","Uberaba","Governador Valadares","Ipatinga","Santa Luzia","Sete Lagoas","Divinópolis","Ibirité","Poços de Caldas","Patos de Minas","Teófilo Otoni","Sabará","Pouso Alegre","Barbacena","Varginha","Outra"],';
echo '"BA": ["Salvador","Feira de Santana","Vitória da Conquista","Camaçari","Itabuna","Juazeiro","Lauro de Freitas","Ilhéus","Jequié","Teixeira de Freitas","Alagoinhas","Barreiras","Porto Seguro","Simões Filho","Paulo Afonso","Eunápolis","Santo Antônio de Jesus","Valença","Candeias","Guanambi","Outra"],';
echo '"PR": ["Curitiba","Londrina","Maringá","Ponta Grossa","Cascavel","São José dos Pinhais","Foz do Iguaçu","Colombo","Guarapuava","Paranaguá","Araucária","Toledo","Apucarana","Pinhais","Campo Largo","Almirante Tamandaré","Umuarama","Paranavaí","Piraquara","Cambé","Outra"],';
echo '"RS": ["Porto Alegre","Caxias do Sul","Pelotas","Canoas","Santa Maria","Gravataí","Viamão","Novo Hamburgo","São Leopoldo","Rio Grande","Alvorada","Passo Fundo","Sapucaia do Sul","Uruguaiana","Santa Cruz do Sul","Cachoeirinha","Bagé","Bento Gonçalves","Erechim","Guaíba","Outra"],';
echo '"PE": ["Recife","Jaboatão dos Guararapes","Olinda","Caruaru","Petrolina","Paulista","Cabo de Santo Agostinho","Camaragibe","Garanhuns","Vitória de Santo Antão","Igarassu","São Lourenço da Mata","Abreu e Lima","Santa Cruz do Capibaribe","Ipojuca","Serra Talhada","Araripina","Palmares","Escada","Outra"],';
echo '"CE": ["Fortaleza","Caucaia","Juazeiro do Norte","Maracanaú","Sobral","Crato","Itapipoca","Maranguape","Iguatu","Quixadá","Canindé","Pacajus","Aquiraz","Quixeramobim","Cascavel","Pacatuba","Horizonte","Russas","Crateús","Tianguá","Outra"],';
echo '"PA": ["Belém","Ananindeua","Santarém","Marabá","Castanhal","Parauapebas","Itaituba","Cametá","Bragança","Abaetetuba","Marituba","Altamira","Tucuruí","Barcarena","Paragominas","Breves","Tailândia","Benevides","Outra"],';
echo '"SC": ["Florianópolis","Joinville","Blumenau","São José","Criciúma","Chapecó","Itajaí","Jaraguá do Sul","Lages","Palhoça","Balneário Camboriú","Brusque","Tubarão","São Bento do Sul","Caçador","Camboriú","Navegantes","Concórdia","Rio do Sul","Araranguá","Outra"],';
echo '"GO": ["Goiânia","Aparecida de Goiânia","Anápolis","Rio Verde","Luziânia","Águas Lindas de Goiás","Valparaíso de Goiás","Trindade","Formosa","Novo Gama","Itumbiara","Senador Canedo","Catalão","Jataí","Planaltina","Caldas Novas","Santo Antônio do Descoberto","Outra"],';
echo '"MA": ["São Luís","Imperatriz","São José de Ribamar","Timon","Caxias","Codó","Paço do Lumiar","Açailândia","Bacabal","Balsas","Santa Inês","Pinheiro","Pedreiras","Chapadinha","São Mateus","Barra do Corda","Outra"],';
echo '"PB": ["João Pessoa","Campina Grande","Santa Rita","Patos","Bayeux","Sousa","Cajazeiras","Cabedelo","Guarabira","Mamanguape","Monteiro","Pombal","Itabaiana","Esperança","Outra"],';
echo '"ES": ["Vila Velha","Serra","Cariacica","Vitória","Cachoeiro de Itapemirim","Linhares","São Mateus","Colatina","Guarapari","Aracruz","Viana","Nova Venécia","Barra de São Francisco","Outra"],';
echo '"AM": ["Manaus","Parintins","Itacoatiara","Manacapuru","Coari","Tefé","Tabatinga","Maués","Humaitá","São Gabriel da Cachoeira","Outra"],';
echo '"RN": ["Natal","Mossoró","Parnamirim","São Gonçalo do Amarante","Macaíba","Ceará-Mirim","Caicó","Assu","Currais Novos","São José de Mipibu","Outra"],';
echo '"AL": ["Maceió","Arapiraca","Rio Largo","Palmeira dos Índios","União dos Palmares","Penedo","São Miguel dos Campos","Santana do Ipanema","Delmiro Gouveia","Coruripe","Outra"],';
echo '"MT": ["Cuiabá","Várzea Grande","Rondonópolis","Sinop","Tangará da Serra","Cáceres","Sorriso","Lucas do Rio Verde","Barra do Garças","Alta Floresta","Outra"],';
echo '"MS": ["Campo Grande","Dourados","Três Lagoas","Corumbá","Ponta Porã","Sidrolândia","Naviraí","Nova Andradina","Aquidauana","Paranaíba","Outra"],';
echo '"DF": ["Brasília","Ceilândia","Taguatinga","Samambaia","Planaltina","Águas Claras","Gama","Santa Maria","São Sebastião","Recanto das Emas","Sobradinho","Outra"],';
echo '"SE": ["Aracaju","Nossa Senhora do Socorro","Lagarto","Itabaiana","Estância","São Cristóvão","Tobias Barreto","Simão Dias","Propriá","Outra"],';
echo '"RO": ["Porto Velho","Ji-Paraná","Ariquemes","Vilhena","Cacoal","Jaru","Rolim de Moura","Guajará-Mirim","Pimenta Bueno","Outra"],';
echo '"TO": ["Palmas","Araguaína","Gurupi","Porto Nacional","Paraíso do Tocantins","Colinas do Tocantins","Guaraí","Miracema do Tocantins","Outra"],';
echo '"AC": ["Rio Branco","Cruzeiro do Sul","Sena Madureira","Tarauacá","Feijó","Brasiléia","Outra"],';
echo '"AP": ["Macapá","Santana","Laranjal do Jari","Oiapoque","Mazagão","Outra"],';
echo '"RR": ["Boa Vista","Rorainópolis","Caracaraí","Mucajaí","Pacaraima","Outra"],';
echo '"PI": ["Teresina","Parnaíba","Picos","Piripiri","Floriano","Campo Maior","Barras","Altos","Outra"]';
echo '};';
echo 'const ufSelect = document.getElementById("address_state");';
echo 'const cidadeSelect = document.getElementById("address_city");';
echo 'if(ufSelect && cidadeSelect){';
echo 'ufSelect.addEventListener("change", function(){';
echo 'const uf = this.value;';
echo 'cidadeSelect.innerHTML = "<option value=\\"\\">Selecione...</option>";';
echo 'if(uf && cidadesPorEstado[uf]){';
echo 'cidadesPorEstado[uf].forEach(function(cidade){';
echo 'const opt = document.createElement("option");';
echo 'opt.value = cidade;';
echo 'opt.textContent = cidade;';
echo 'cidadeSelect.appendChild(opt);';
echo '});';
echo '}';
echo '});';
echo '}';
echo '</script>';

if ($isPublic) {
    echo '</div>';
    echo '</body>';
    echo '</html>';
} else {
    view_footer();
}
