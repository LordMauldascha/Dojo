import web from '@sulu/web';
import Dialog from './components/dialog';
import RunComparison from './components/runComparison';
import RunProgress from './components/runProgress';
import SmashLogout from './components/smashLogout';
import TatamiCover from './components/tatamiCover';
import TatamiLogin from './components/tatamiLogin';
import UnsavedChanges from './components/unsavedChanges';
import commandButtons from './services/commandButtons';
import passwordToggle from './services/passwordToggle';
import preventDoubleSubmit from './services/preventDoubleSubmit';
import skillHeader from './services/skillHeader';

web.registerComponent('dialog', Dialog);
web.registerComponent('run-comparison', RunComparison);
web.registerComponent('run-progress', RunProgress);
web.registerComponent('smash-logout', SmashLogout);
web.registerComponent('tatami-cover', TatamiCover);
web.registerComponent('tatami-login', TatamiLogin);
web.registerComponent('unsaved-changes', UnsavedChanges);

web.registerService('command-buttons', commandButtons);
web.registerService('password-toggle', passwordToggle);
web.registerService('prevent-double-submit', preventDoubleSubmit);
web.registerService('skill-header', skillHeader);
