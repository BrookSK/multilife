# Script para Adicionar page_history_log em Todos os Arquivos _post.php

## Lista de Arquivos que Precisam de page_history_log

### Já Implementados ✅
- patients_create_post.php
- patients_edit_post.php
- patients_delete_post.php
- demands_create_post.php
- demands_edit_post.php
- users_create_post.php
- users_edit_post.php
- users_delete_post.php

### Pendentes (56 arquivos)

#### Admin
1. admin_integrations_post.php
2. admin_logo_upload_post.php
3. admin_openai_console_post.php
4. admin_settings_post.php
5. admin_whatsapp_console_post.php
6. admin_whatsapp_instance_connect_post.php
7. admin_whatsapp_instance_create_post.php
8. admin_whatsapp_instance_delete_post.php
9. admin_whatsapp_instance_logout_post.php
10. admin_whatsapp_instance_restart_post.php
11. admin_whatsapp_send_text_post.php
12. admin_zapsign_create_doc_post.php
13. admin_zapsign_detail_doc_post.php

#### Profissionais
14. apply_professional_post.php
15. professional_applications_approve_post.php
16. professional_applications_need_more_info_post.php
17. professional_applications_reject_post.php
18. professional_docs_approve_post.php
19. professional_docs_create_post.php
20. professional_docs_edit_post.php
21. professional_docs_reject_post.php
22. professional_docs_submit_post.php

#### Agendamentos
23. appointment_value_authorizations_approve_post.php
24. appointment_value_authorizations_reject_post.php
25. appointments_cancel_post.php
26. appointments_close_cycle_post.php
27. appointments_create_post.php
28. appointments_edit_post.php
29. appointments_renew_cycle_post.php
30. appointments_set_status_post.php

#### Chat
31. chat_confirm_admission_post.php
32. chat_create_group_post.php
33. chat_finalize_post.php
34. chat_link_contact_post.php
35. chat_open_professional_post.php
36. chat_reopen_post.php
37. chat_send_post.php
38. chat_transfer_post.php

#### Demandas (restantes)
39. demands_assume_post.php
40. demands_cancel_post.php
41. demands_delete_post.php
42. demands_dispatch_whatsapp_post.php
43. demands_reactivate_post.php
44. demands_release_post.php
45. demands_set_status_post.php

#### Documentos
46. documents_archive_post.php
47. documents_upload_post.php
48. documents_version_upload_post.php

#### Financeiro
49. finance_payable_mark_paid_post.php
50. finance_receivable_set_status_post.php

#### RH
51. hr_employees_create_post.php
52. hr_employees_delete_post.php
53. hr_employees_edit_post.php

#### Outros
54. backup_runs_run_post.php
55. evolution_instance_create_post.php
56. integration_jobs_run_post.php

## Padrão de Implementação

```php
// Após a ação principal (INSERT/UPDATE/DELETE)
page_history_log(
    '/pagina_de_destino.php',           // URL da página de listagem ou visualização
    'Título da Página',                  // Título descritivo
    'create|update|delete|action',       // Tipo de ação
    'Descrição da ação realizada',       // Descrição detalhada
    'entity_type',                       // Tipo da entidade (opcional)
    $entityId                            // ID da entidade (opcional)
);
```

## Exemplos por Tipo

### Create
```php
page_history_log(
    '/appointments_list.php',
    'Agendamentos',
    'create',
    'Criou novo agendamento para paciente: ' . $patientName,
    'appointment',
    (int)$appointmentId
);
```

### Update
```php
page_history_log(
    '/appointments_view.php?id=' . $id,
    'Agendamento',
    'update',
    'Atualizou dados do agendamento',
    'appointment',
    $id
);
```

### Delete
```php
page_history_log(
    '/appointments_list.php',
    'Agendamentos',
    'delete',
    'Cancelou agendamento: ' . $appointmentId,
    'appointment',
    $id
);
```

### Status Change
```php
page_history_log(
    '/demands_view.php?id=' . $id,
    'Demanda',
    'status_change',
    'Alterou status de "' . $oldStatus . '" para "' . $newStatus . '"',
    'demand',
    $id
);
```

### Approve/Reject
```php
page_history_log(
    '/professional_applications_list.php',
    'Candidaturas',
    'approve',
    'Aprovou candidatura de: ' . $professionalName,
    'professional_application',
    $id
);
```

## Prioridade de Implementação

1. **Alta**: Demandas, Agendamentos, Candidaturas
2. **Média**: Chat, Documentos, Financeiro
3. **Baixa**: Admin, Integrações, Backup
