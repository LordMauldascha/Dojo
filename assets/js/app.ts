import '@sulu/web';
import { showDialogsOnLoad, supportCommandButtons } from './commandButtons';
import { enablePasswordToggles } from './passwordToggle';
import { preventDoubleSubmit } from './preventDoubleSubmit';
import { warnOnUnsavedChanges } from './unsavedChanges';
import { smashLogout } from './smashLogout';
import { rollAwayAfterLoad, rollInOnLogin } from './tatamiLogin';

preventDoubleSubmit();
supportCommandButtons();
showDialogsOnLoad();
enablePasswordToggles();
warnOnUnsavedChanges();
smashLogout();
rollInOnLogin();
rollAwayAfterLoad();
