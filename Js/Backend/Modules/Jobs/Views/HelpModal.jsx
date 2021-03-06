import React from 'react';
import Webiny from 'webiny';

/**
 * @i18n.namespace CronManager.Backend.Jobs.HelpModal
 */
class HelpModal extends Webiny.Ui.ModalComponent {

    renderDialog() {
        const {Modal, Copy, Button} = this.props;
        return (
            <Modal.Dialog>
                <Modal.Content>
                    <Modal.Header title={this.i18n('Help')}/>
                    <Modal.Body>
                        <h3>{this.i18n('About')}</h3>
                        <p>
                            {this.i18n('Cron Manager is a tool used to schedule and execute cron jobs using a simple interface.')}
                        </p>
                        <p>
                            {this.i18n(`For each cron job the app automatically saves execution data and all responses for last 30 days.
                                        This way you can easily track the execution of your cron jobs.`)}
                            <br/>
                            {this.i18n(`The grid presented behind automatically refreshes and changes the status for each scheduled job.
                                        This way you can immediately know if a job is active, scheduled or currently running.`)}
                        </p>
                        <h3>{this.i18n('Setup')}</h3>
                        <p>
                            {this.i18n('On your server make sure you define the following cron job:')}
                        </p>
                        <Copy.Input
                            value={`* * * * * wget ${Webiny.Config.ApiUrl}/services/cron-manager/runner/run --no-check-certificate -O /dev/null`}/>
                        <p>
                            {this.i18n(`This is the root job that's used to execute and schedule any other jobs created via Cron Manager.`)}
                        </p>
                    </Modal.Body>
                    <Modal.Footer>
                        <Button label={this.i18n('Close')} onClick={this.hide}/>
                    </Modal.Footer>
                </Modal.Content>
            </Modal.Dialog>
        );
    }
}

export default Webiny.createComponent(HelpModal, {modules: ['Modal', 'Copy', 'Button']});