import { startStimulusApp } from '@symfony/stimulus-bundle';
import RagProfileSwitchController from './controllers/rag_profile_switch_controller.js';

const app = startStimulusApp();
// register any custom, 3rd party controllers here
// app.register('some_controller_name', SomeImportedController);
app.register('rag-profile-switch', RagProfileSwitchController);
