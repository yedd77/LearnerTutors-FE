<?php

// LearnerTutors landing page
$string['pluginname'] = 'LearnerTutors'; 
$string['dashboardheading'] = 'LearnerTutors'; 
$string['uploadCardTitle'] = 'Manage Course Materials'; 
$string['uploadCardDesc'] = 'Upload and manage learning materials available to the assistant for this course.'; 
$string['uploadButton'] = 'Manage documents'; 
$string['chatnopermission'] = 'You do not have permission to use the AI chat in this course.';
 
// Upload document form components
$string['submitdocument'] = 'Upload course material document';
$string['submitdocumentdesc'] = 'Add the learning materials to become context for the assistant';
$string['documentfile'] = 'Document file(s)';
$string['documentfile_help'] = 'Select PDF, DOCX, PPTX, TXT, or Markdown file(s), up to 20 MB. Maximum 20 files per upload.';
$string['documentreviewtitle'] = 'Review files';
$string['documentreviewdesc'] = 'Review and add additional notes for each files';
$string['uploadfilesbutton'] = 'Upload all files';
$string['uploadfilesbutton_tooltip'] = 'Upload document(s) to be processed';
$string['disableretrieval_tooltip'] = 'When this document is disabled, the AI will not use it as a reference in future conversations.';
$string['enableretrieval_tooltip'] = 'When this document is enabled, the AI will use it as a reference in future conversations.';
$string['indexdocument_tooltip'] = 'Process this document so the AI can use it in conversations.';
$string['reindexdocument_tooltip'] = 'Process this document again and refresh its indexed content.';
$string['deletedocument_tooltip'] = 'Permanently delete this document and its indexed content.';
$string['processingdocument_tooltip'] = 'This document is being processed. Actions will be available when it finishes.';
$string['generatesuggestion_tooltip'] = 'Generate a suggested question for students from this document.';
$string['regeneratesuggestion_tooltip'] = 'Replace this suggestion with a newly generated one.';
$string['generatingsuggestion_tooltip'] = 'This suggestion is being generated.';
$string['savepolicy_tooltip'] = 'Save the chat settings for this course.';
$string['cancelupload_tooltip'] = 'Cancel and discard the selected files.';
$string['lecturernote'] = 'Lecturer note';
$string['retrieval'] = 'Retrieval';
$string['uploadedDocuments'] = 'Manage uploaded documents';
$string['manageUploaded'] = 'Disable or delete uploaded documents. Disabling a document prevents it from being used by the assistant, but does not delete it. Deleting a document removes it permanently.';

// Chatbot page components
$string['chatwelcome'] = 'Ask questions about your course materials. Answers will eventually include references to source documents.';
$string['chatprivacyinfo'] = 'Your question and the text retrieved from documents available to you in this course are sent to our configured AI model. Do not submit passwords or other sensitive personal information.';
$string['chatinputhint'] = 'AI can make mistake. Do verify important information against the cited source.';
$string['sendquestion'] = 'Send';
$string['questionplaceholder'] = 'For example: Summarise the main concepts in Week 3 notes.';
$string['yourquestion'] = 'Your question';
$string['sourcepage'] = 'Page / slide';
$string['sourcesheading'] = 'Sources used';
$string['unknown'] = 'Unknown';
$string['questionrequired'] = 'Enter a question with at least two characters.';
$string['questiontoolong'] = 'Keep your question under 4,000 characters.';


// Course chat settings card (on the course materials page)
$string['chatsettingstitle'] = 'Chat Settings';
$string['chatsettingsdesc'] = 'Choose whether enrolled students may use the AI chat in this course. Changes apply to this course only.';
$string['allowstudentchat'] = 'Allow students to use the AI chat';
$string['allowstudentchat_desc'] = 'When disabled, students cannot open or submit questions to the assistant in this course. Educators with document-management permission remain able to use it.';
$string['policysaved'] = 'Course chat settings saved.';

// Utility strings
$string['backtodashboard'] = 'Back to LearnerTutors';


$string['uploadfailed'] = 'The file was not stored. Please try again.';
$string['uploadnotpermitted'] = 'You do not have permission to upload documents in this course.';
$string['documentname'] = 'Document';
$string['visibility'] = 'Visi   bility';
$string['processingstatus'] = 'Processing status';
$string['retrieval'] = 'Retrieval';
$string['dateuploaded'] = 'Date uploaded';
$string['personal'] = 'Uploader only';
$string['course'] = 'Course participants';
$string['pendingfilename'] = 'Uploaded file';
$string['status_pending'] = 'Pending indexing';
$string['status_processing'] = 'Processing';
$string['status_ready'] = 'Ready';
$string['status_failed'] = 'Failed';
$string['nodocuments'] = 'No documents have been uploaded for this course yet.';
$string['nogenerateddocuments'] = 'No generated documents are available yet.';



$string['chatdisabledreason'] = 'Chat is disabled in site settings. Configure and test FastAPI and the selected AI provider before enabling it.';


$string['apikeymissing'] = 'The RAG API key is not configured. Ask a site administrator to configure it.';
$string['backendconnectionfailed'] = 'Could not connect to the RAG backend. Check that FastAPI is running and the base URL is correct.';
$string['backendrequestfailed'] = 'The RAG backend rejected the request: {$a}';
$string['backendinvalidresponse'] = 'The RAG backend returned an invalid response.';
$string['filetempfailed'] = 'Moodle could not prepare the stored document for indexing.';
$string['storedfilemissing'] = 'The stored document file could not be found.';
$string['indexfailedconnection'] = 'The assistant server could not be reached. Try again later.';
$string['indexfailedgeneric'] = 'Indexing failed. Try again, or contact your administrator if it keeps failing.';
$string['indexfailedreason'] = 'Reason: {$a}';
$string['documentduplicate'] = '{$a->filename} is already in this course as "{$a->existing}". The same document can only be uploaded once.';
$string['documentduplicateupload'] = '{$a->first} and {$a->second} are the same file. Upload it only once.';
$string['pdftoomanypages'] = '{$a->filename} has {$a->pages} pages. PDFs can have at most {$a->limit} pages: split it into smaller files.';
$string['indexfailedtimeout'] = 'Indexing took too long. Try again, or split the document into smaller files.';
$string['indexsuccess'] = 'Document indexed successfully ({$a} text chunks).';
$string['indexdocument'] = 'Index';
$string['actions'] = 'Actions';

$string['answerfailed'] = 'The assistant could not answer right now. Check that the backend and configured AI provider are available, then try again.';

$string['notavailable'] = 'Not available';



$string['studentchatdisabled'] = 'The lecturer has disabled student access to the AI chat for this course.';

// Plugin Settings compoments
$string['api_base_url'] = 'FastAPI base URL';
$string['api_base_url_desc'] = 'Base URL of your RAG backend, without an API path.';
$string['api_key'] = 'FastAPI API key';
$string['api_key_desc'] = 'API key used to authenticate requests to the RAG backend.';
$string['reindexpending'] = 'The embedding configuration has changed. Course documents are being reindexed so their vectors remain compatible. Chat-generation model changes do not require reindexing. Please try again after Moodle scheduled tasks have completed.';



// Settings page components
// Plugin Settings compoments
$string['settingsheading'] = 'Plugin settings';
$string['settingsintro'] = 'Configure the local FastAPI service. Do not expose this service publicly without authentication and network restrictions.';
$string['apibaseurl'] = 'Server API URL';
$string['apibaseurl_desc'] = 'URL used for API call to the server.';
$string['apikey'] = 'Server API key';
$string['apikey_desc'] = 'Enter the server API key matching the generated key created during server setup.';
$string['enablechat'] = 'Enable AI chat';
$string['enablechat_desc'] = 'Global setting to enable AI chat feature. If unchecked, the AI chat feature will be disabled for all courses but indexing and document management will still be available.';
// LLM config settings 
$string['llm_heading'] = 'LLM configuration';
$string['llm_heading_desc'] = 'Configure the LLM settings for the RAG system.';
$string['llmprovider'] = 'LLM provider';
$string['llmprovider_desc'] = 'Select the LLM provider used for chat generation.';
$string['llm_model'] = 'LLM model';
$string['llm_model_desc'] = 'Enter the LLM model identifier corresponding to the selected provider.';
$string['provider_api_key'] = 'LLM API key';
$string['provider_api_key_desc'] = 'Enter the API key for the provider selected above (OpenAI or Google Gemini).';
// Image LLM config settings
$string['image_heading'] = 'LLM for Image generation configuration';
$string['image_heading_desc'] = 'Configure the LLM for image generation settings for the RAG system.';
$string['image_provider'] = 'LLM for Image provider';
$string['image_provider_desc'] = 'Select the LLM provider for image generation.';
$string['image_model'] = 'Image generation model';
$string['image_model_desc'] = 'Enter the model identifier for the selected LLM for image generation provider.';
$string['reuse_chat_api_key'] = 'Reuse chat provider API key';
$string['reuse_chat_api_key_desc'] = 'If the provider for image generation is the same as the chat provider, and use the same API key, you can reuse the chat provider API key. ';
$string['image_provider_api_key'] = 'Image generation provider API key';
$string['image_provider_api_key_desc'] = 'Enter the API key for the selected image generation provider.';
// Text embedding model configuration
$string['embedding_heading'] = 'Text embedding model configuration';
$string['embedding_heading_desc'] = 'Configure the text embedding model used for document indexing and retrieval.';
$string['embedding_provider'] = 'Text embedding provider';
$string['embedding_provider_desc'] = 'Select the provider for the text embedding model.';
$string['embedding_provider_server'] = 'Use the server setting';
$string['embedding_change_warning'] = 'Changing the model may reduce RAG performance and will trigger automatic reindexing. This process can consume a significant number of tokens, depending on how many documents have already been uploaded.';
$string['embedding_reuse_chat_api_key'] = 'Reuse chat provider API key';
$string['embedding_reuse_chat_api_key_desc'] = 'If the text embedding provider is the same as the chat provider and uses the same API key, you can reuse the chat provider API key.';
$string['embedding_model'] = 'Text embedding model';
$string['embedding_model_desc'] = 'Enter the text embedding model identifier corresponding to the selected provider.';
$string['embedding_provider_api_key'] = 'Text embedding provider API key';
$string['embedding_provider_api_key_desc'] = 'Enter the API key for the selected text embedding';
// Local LLM fallback
$string['fallback_heading'] = 'Local LLM fallback';
$string['fallback_heading_desc'] = 'Configure the local Ollama model. This model will be run inside local environments when the primary cloud model call fails. The provider is fixed to Ollama.';
$string['fallback_model'] = 'Fallback model';
$string['fallback_model_desc'] = 'Enter the Ollama model identifier installed on the server';
// Configuration health check
$string['configurationhealth_heading'] = 'Configuration Health Checks';
$string['configurationhealth_heading_desc'] = 'Check the connectivity of the RAG system and the availability of the configured LLM providers and models. Before the changes are saved, run the tests and fix any failed checks. ';
$string['configurationhealth'] = 'Test RAG configuration';
$string['configurationhealth_desc'] = 'Check the connectivity of the RAG system and the availability of the configured LLM providers and models.';
$string['healthcheck_test_server'] = 'Application Server';
$string['healthcheck_test_llm'] = 'LLM provider and model';
$string['healthcheck_test_image'] = 'Image generation provider and model';
$string['healthcheck_test_embedding'] = 'Text embedding provider and model';
$string['healthcheck_test_fallback'] = 'Local fallback model (Ollama)';



$string['encryptionkeymissing'] = 'Moodle could not access its protected data directory to initialize secure credential storage. Check the dataroot path and PHP write permissions.';
$string['encryptionkeyinvalid'] = 'The configured Moodle credential encryption key is invalid, or the saved key file is damaged. Restore the original key from backup; do not generate a replacement while encrypted credentials exist.';
$string['secretencryptionfailed'] = 'Moodle could not encrypt the application API key.';
$string['secretdecryptionfailed'] = 'Moodle could not decrypt the stored application API key. Verify that the original Moodle credential encryption key or moodledata key file has been restored.';
$string['apikeytoolong'] = 'Use an application API key with at least 32 characters.';
$string['secretstoragefailed'] = 'The application API key could not be stored securely.';
$string['apikey_desc_secure'] = 'Enter the shared key matching RAG_API_KEY on FastAPI. The field is blank to avoid sending the saved key to your browser. Leave blank to keep the current key. Moodle encrypts the value at rest using an automatically generated key stored in the protected moodledata directory. Advanced deployments may provide LOCAL_RAGASSISTANT_ENCRYPTION_KEY or $CFG->local_ragassistant_encryption_key.';
$string['testconnection'] = 'Test FastAPI connection';
$string['testconnectionheading'] = 'Test FastAPI authentication';
$string['testconnectionintro'] = 'Send a server-to-server authentication test using the configured URL and shared key. The key is never displayed.';
$string['testconnectionsuccess'] = 'FastAPI connection and API-key authentication succeeded.';
$string['testconnectionfailed'] = 'The connection test failed: {$a}';
$string['backendhttpsrequired'] = 'The FastAPI URL must use HTTPS. HTTP is allowed only for localhost or loopback development addresses.';

// AI provider credential settings


// Combined provider credential setting
$string['providerkeynotrequired'] = 'Ollama does not require an API key. Select OpenAI or Google Gemini to save a provider credential.';
$string['providerkeytoolong'] = 'Use a provider API key with at least 20 characters.';


// Encrypted credential status labels (never display credential values).
$string['credentialconfigured'] = 'Configured — saved credential is available';
$string['credentialnotconfigured'] = 'Not configured — enter an API key';
$string['credentialnotrequired'] = 'Not required for the selected provider';
$string['credentialverificationfailed'] = 'A saved credential exists but could not be verified. Check the encryption key and restore it from backup if necessary.';
$string['credentialinputplaceholder'] = '*******************************';



//settings
$string['runconfigurationtests'] = 'Run Configuration Tests';
$string['healthcheck_apikeymissing'] = 'No application API key is saved. Enter it above and save the settings first.';
$string['healthcheck_llmmodelmissing'] = 'Enter a chat model before running the tests.';
$string['healthcheck_needsmodel'] = 'Not tested because the backend needs a chat model to run any provider test.';
$string['healthcheck_notreported'] = 'The backend did not report a result for this test.';
$string['healthcheck_requestfailed'] = 'Unable to complete the configuration tests. Check your settings and backend connection.';
$string['healthcheck_requires_backend'] = 'Not tested because the application server check failed.';
$string['healthcheck_server_fail'] = 'Could not reach the backend. Check that FastAPI is running and the saved backend URL is correct.';
$string['healthcheck_server_pass'] = 'Reachable, and the application API key was accepted.';
$string['healthcheck_status_fail'] = 'Failed';
$string['healthcheck_status_pass'] = 'Passed';
$string['healthcheck_status_skipped'] = 'Skipped';







$string['healthcheck_status_idle'] = 'Not tested';
$string['healthcheck_status_testing'] = 'Testing...';
$string['healthcheck_running'] = 'Running tests...';
$string['healthcheck_summary'] = '{$a->pass} passed, {$a->fail} failed, {$a->skipped} skipped';
$string['healthcheck_test_cron'] = 'Background tasks (cron)';
$string['healthcheck_testing_row'] = 'Testing {$a}...';
$string['healthcheck_cron_never'] = 'Moodle cron has never run. Document indexing runs as a background task, so uploads will not be processed until cron is set up.';
$string['healthcheck_cron_overdue'] = 'Moodle cron last ran {$a} ago. Document indexing waits until cron runs again, so check the cron schedule.';
$string['healthcheck_cron_failing'] = '{$a} document indexing task(s) are failing and waiting to retry. Check Site administration > Server > Tasks > Ad hoc tasks.';
$string['healthcheck_cron_waiting'] = '{$a} document indexing task(s) have been waiting for more than an hour.';
$string['healthcheck_cron_pass'] = 'Cron last ran {$a} ago, and no indexing tasks are stuck.';
$string['healthcheck_cron_error'] = 'Could not check the background tasks.';
$string['healthcheck_keysaved'] = 'A key is saved for this provider. Leave blank to keep it.';
$string['healthcheck_keynone'] = 'No key is saved for this provider yet.';
$string['healthcheck_savelocked'] = 'Run the configuration tests below. Every check must pass before these changes can be saved.';
$string['healthcheck_elapsed'] = '{$a} s elapsed. AI checks can take up to a minute.';
$string['healthcheck_stale'] = 'Settings changed. Run the tests again.';
$string['healthcheck_notneeded'] = 'Not needed: chat already uses Ollama.';
$string['healthcheck_embedding_notneeded'] = 'Not needed: the server\'s own embedding setting is used.';
$string['assistantname'] = 'LearnerTutors';
// Capability names (shown on permission pages and in "no permission" errors).
$string['ragassistant:useassistant'] = 'Use the LearnerTutors AI chat';
$string['ragassistant:managedocuments'] = 'Manage LearnerTutors course documents';
$string['ragassistant:reindexdocuments'] = 'Reindex LearnerTutors course documents';
$string['nomanagedocuments'] = 'Only lecturers can manage course materials.';

// Study sheet (/notes) PDF download.
$string['downloadpdf'] = 'Download PDF';
$string['downloadpdfaria'] = 'Download PDF: {$a}';
$string['downloadavailableuntil'] = 'Available until {$a}';
$string['downloadbusy'] = 'Downloading…';
$string['downloadexpired'] = 'This study sheet has expired. Send /notes again to create a new one.';
$string['downloadfailed'] = 'The download did not complete. Check your connection and try again.';
$string['downloadunavailable'] = 'The study sheet could not be downloaded right now. Try again later.';
// Figures from course documents shown in chat answers.
$string['figureunavailable'] = 'This figure is no longer available.';
$string['figuresourcepage'] = '{$a->filename}, p. {$a->page}';
$string['figurealt'] = 'Figure from {$a}';
$string['figureopen'] = 'Open the full-size figure in a new tab';


// /quiz multiple-choice practice quiz.
$string['quizaria'] = 'Practice quiz';
$string['quizcheck'] = 'Check answer';
$string['quizcorrect'] = 'Correct!';
$string['quizfinished'] = 'Finished';
$string['quizincorrect'] = 'Not quite — the answer is {$a}.';
$string['quiznext'] = 'Next question';
$string['quizoptions'] = 'Answer options';
$string['quizpercent'] = '{$a}% correct. Review the ones you missed, then try again.';
$string['quizquestionof'] = 'Question {$a->current} of {$a->total}';
$string['quizretry'] = 'Retry quiz';
$string['quizscorearia'] = 'Score {$a->score} out of {$a->total}';
$string['quizscoresofar'] = 'Score so far: {$a->score} / {$a->answered}';
$string['quizseescore'] = 'See my score';
$string['quizsource'] = 'Source: {$a}';
$string['quizverdictclose'] = 'Getting there';
$string['quizverdictexcellent'] = 'Excellent work!';
$string['quizverdictgood'] = 'Good job!';
$string['quizverdictpractise'] = 'Keep practising';

// Chat composer.
$string['charactercount'] = '{$a->count} / {$a->max}';
$string['studytools'] = 'Study tools';

// /flashcards revision deck.
$string['flashcardanswer'] = 'Answer';
$string['flashcardanswershown'] = 'Answer shown';
$string['flashcardarialabelanswer'] = 'Answer: {$a}. Show the question';
$string['flashcardarialabelquestion'] = 'Question: {$a}. Show the answer';
$string['flashcardcount'] = 'Card {$a->current} of {$a->total}';
$string['flashcardfliphint'] = 'Click or press Space to flip';
$string['flashcardfliphinttouch'] = 'Tap to flip';
$string['flashcardnext'] = 'Next card';
$string['flashcardprevious'] = 'Previous card';
$string['flashcardquestion'] = 'Question';
$string['flashcardquestionshown'] = 'Question shown';
$string['flashcardrestart'] = 'Start again';
$string['flashcardsaria'] = 'Flashcards';
$string['flashcardsource'] = 'Source: {$a}';

// Past exam papers: their structure (not their questions) shapes /exam practice papers.
$string['documentrole_past_exam'] = 'Past exam paper';
$string['pastexamstructure'] = 'Detected exam structure';
$string['pastexamenable'] = 'Use for /exam';
$string['pastexamdisable'] = 'Stop using for /exam';
$string['pastexamenable_tooltip'] = '/exam practice papers will follow the structure of this past paper.';
$string['pastexamdisable_tooltip'] = '/exam practice papers will no longer follow the structure of this past paper. The structure is kept.';
$string['pastexamenabled'] = 'This past paper is now used for /exam practice papers.';
$string['pastexamdisabled'] = 'This past paper is no longer used for /exam practice papers.';
$string['pastexamused'] = 'Used for /exam';
$string['pastexamnotused'] = 'Not used';
$string['pastexamunsupported'] = 'The assistant server does not support past exam papers yet, so the file was not kept. Ask your administrator to update the server.';
$string['pastexamupload'] = 'Upload past exam papers';
$string['pastexamuploaddesc'] = 'Students\' /exam practice papers follow the format of these papers. Only the structure is kept: sections, question types, marks and time allowed. The papers are not searchable in the chat, and students never see their questions.';
$string['pastexamfile'] = 'Past exam paper(s)';
$string['pastexamfile_help'] = 'Select PDF, DOCX, PPTX, TXT, or Markdown file(s), up to 20 MB. Maximum 20 files per upload. When a student uses /exam, the assistant writes new questions from the course materials in the structure of these papers and gives them a PDF practice paper with no answers. With several past papers, it combines them into one typical structure. Scanned PDFs without text cannot be analysed.';
$string['pastexamuploadbutton'] = 'Upload past papers';
$string['pastexamuploadbutton_tooltip'] = 'Upload past exam paper(s) and detect their structure';
$string['pastexamsnone'] = 'No past paper is in use yet, so /exam answers in the chat as usual.';
$string['pastexamsinuse'] = '{$a} past paper(s) in use: /exam gives students a PDF practice paper in this format.';
$string['coursematerialslist'] = 'Course materials';
$string['pastexamlist'] = 'Past exam papers';
$string['pastexamlistdesc'] = 'The papers /exam practice papers are modelled on. Stop using a paper to leave it out of /exam without deleting it.';
$string['pastexamlistempty'] = 'No past exam papers yet. Upload one above so /exam follows your format.';
$string['pastexamusedcolumn'] = 'Used for /exam';
$string['allowstudentexam'] = 'Allow students to use /exam';
$string['allowstudentexam_desc'] = 'When this is off, students cannot use /exam to generate exam questions or practice exam papers, whether or not you have uploaded past papers. Educators with document-management permission can still use it.';
$string['examdisabled'] = 'The /exam command is turned off in this course.';
$string['pastexamreviewtitle'] = 'Review past exam papers';
$string['pastexamreviewdesc'] = 'Check these are past exam papers before uploading. Only each paper\'s structure will be kept for /exam.';
$string['pastexamreviewitem'] = 'Paper';
