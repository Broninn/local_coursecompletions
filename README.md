# 📊 Moodle Course Completion Report Plugin (Possíveis Evasões)

Um plugin local para o Moodle que exibe um relatório de conclusão de curso com filtro por grupo e exportação para planilha Excel (XLS).  
Ideal para instituições que desejam acompanhar o progresso dos alunos com mais clareza e praticidade.

---

## ✨ Funcionalidades

- 📌 **Relatório de conclusão de curso**: Lista usuários matriculados com status de conclusão.
- 👥 **Filtro por grupo**: Visualize apenas os participantes de um grupo específico do curso.
- 📤 **Exportação para Excel**: Baixe os dados do relatório em formato `.xls`.
- 🔒 **Controle de permissão**: Apenas usuários com a capacidade `local/coursecompletions:view` podem acessar o relatório.

---

## 📂 Estrutura

```
local/coursecompletions/
├── index.php             # Página principal do relatório
├── version.php           # Metadados do plugin
├── db/access.php         # Permissões de acesso
└── lang/en/local_coursecompletions.php  # Idiomas
```

---

## 🚀 Instalação

1. Clone este repositório ou baixe o ZIP:
   ```bash
   git clone https://github.com/broninn/moodle-local_coursecompletions.git local/coursecompletions
   ```

2. Coloque a pasta dentro de `local/` no seu diretório Moodle.

3. Acesse o Moodle no navegador como administrador — ele detectará o novo plugin.

4. Siga os passos de instalação na interface do Moodle.

---

## 📌 Obrigações

Certifique-se que seu curso tenha as conclusões ativadas e tenha as regras definidas.

Você pode acessar em: > Curso -> Conclusões do Curso

Verifique se o seu Moodle contém o plugin Dedication instalado

https://moodle.org/plugins/block_dedication

---


## 🔐 Permissões

Certifique-se de conceder a permissão `local/coursecompletions:view` ao papel apropriado (ex: gestor, coordenador).

Você pode fazer isso em:

> Administração do site → Usuários → Permissões → Definir papéis

---

## 🖥️ Como usar

1. Acesse um curso no Moodle.
2. No menu , clique em **Possíveis evasões**.
3. Use o filtro para selecionar um grupo (opcional).
4. Visualize os dados ou clique em **Exportar para Excel** para baixar.

---

## 🛠️ Compatibilidade

| Versão do Moodle | Suporte |
|------------------|---------|
| Moodle 4.0+      | ✅       |

---

## 📃 Licença

Este plugin é distribuído sob a [Licença GPL v3](https://www.gnu.org/licenses/gpl-3.0.html).

---

## 🙌 Contribuições

Pull Requests são bem-vindos!  
Se encontrar algum problema ou tiver sugestões, por favor, abra uma issue.

---

## ✉️ Contato

Desenvolvido por Bruno Henrique da Silva Mosko.  
📧 Email: [bruno-hs@outlook.com](mailto:bruno-hs@outlook.com)
🌐 GitHub: [https://github.com/broninn](https://github.com/broninn)
🌐 WhatsApp:[+(55) 41 9910129877](https://api.whatsapp.com/send?phone=5541991012987)