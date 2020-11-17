import React, { useState } from 'react'
import PropTypes from 'prop-types'
import HelpButton from './help-button'
import FormDateTime from './form-date-time'
import Button from './button'

import './modal-instance-details.scss'

export default function ModalInstanceDetails(props) {
	const [instanceName, setInstanceName] = useState(props.instanceName)
	const [courseName, setCourseName] = useState(props.courseName)
	const [startDate, setStartDate] = useState(props.startDate)
	const [endDate, setEndDate] = useState(props.endDate)
	const [numAttempts, setNumAttempts] = useState(props.numAttempts)
	const [scoringMethod, setScoringMethod] = useState(props.scoringMethod)
	const [isImportAllowed, setIsImportAllowed] = useState(props.isImportAllowed)

	const renderExample = () => {
		if (scoringMethod === 'highest') {
			return (
				<div className="row example">
					<span className="sub-title">Take Highest Example:</span>
					<span>
						Score 1: 75%, Score 2: 90%, Score 3: 80%, <b>Final: 90%</b>
					</span>
				</div>
			)
		} else if (scoringMethod === 'average') {
			return (
				<div className="row example">
					<span className="sub-title">Take Average Example:</span>
					<span>
						Score 1: 75%, Score 2: 90%, Score 3: 80%, <b>Final: 82%</b>
					</span>
				</div>
			)
		} else if (scoringMethod === 'last') {
			return (
				<div className="row example">
					<span className="sub-title">Take Last Example:</span>
					<span>
						Score 1: 75%, Score 2: 90%, Score 3: 80%, <b>Final: 80%</b>
					</span>
				</div>
			)
		}
	}

	const onSave = () => {
		const state = {
			instanceName,
			courseName,
			startDate,
			endDate,
			numAttempts,
			scoringMethod,
			isImportAllowed
		}

		props.onSave(state)
	}

	return (
		<div className="modal-instance-details">
			<h1>{`${props.mode === 'create' ? 'Create' : 'Edit'} Instance Details`}</h1>
			<div className="box">
				<div className="row">
					<span className="title">Instance Name:</span>
					<div className="flex-container">
						<input
							type="text"
							value={instanceName}
							onChange={event => setInstanceName(event.target.value)}
						/>
						<HelpButton>
							<div>
								Your published instance will be displayed to students as the name you input here. By
								default this name is the same as the object name.
							</div>
						</HelpButton>
					</div>
				</div>
				<div className="row">
					<span className="title">Course Name:</span>
					<div className="flex-container">
						<input
							type="text"
							value={courseName}
							onChange={event => setCourseName(event.target.value)}
						/>
						<HelpButton>
							<div>
								This field shows the course for this instance. This field is for your organization
								only - changing it won&apos;t impact how your instance functions.
							</div>
						</HelpButton>
					</div>
				</div>
			</div>
			<div className="box border">
				<div className="row">
					<span className={`title ${props.isExternallyLinked ? 'is-disabled' : 'is-not-disabled'}`}>
						Start Date:
					</span>
					<div className="flex-container">
						<FormDateTime value={startDate} onChange={setStartDate} />
						<HelpButton>
							{props.isExternallyLinked ? (
								<div>
									Since this instance is linked to an external course you cannot set the start date.
									Access to your module is reliant on settings in the external system.
								</div>
							) : (
								<div>
									This is the date when this instance will be opened to students. Before this date,
									students will not be able to access the instance.
								</div>
							)}
						</HelpButton>
					</div>
				</div>
				<div className="row">
					<span className={`title ${props.isExternallyLinked ? 'is-disabled' : 'is-not-disabled'}`}>
						End Date:
					</span>
					<div className="flex-container">
						<FormDateTime value={endDate} onChange={setEndDate} />
						<HelpButton>
							{props.isExternallyLinked ? (
								<div>
									Since this instance is linked to an external course you cannot set the end date.
									Access to your module is reliant on settings in the external system.
								</div>
							) : (
								<div>
									This is the date when the assessment will be closed to students. After this date,
									students will not be able to take assessment attempts. They will still have access
									to the content and practice.
								</div>
							)}
						</HelpButton>
					</div>
				</div>
				<div className="row">
					{props.isExternallyLinked ? (
						<span className="linked">(Start/end dates are defined by the external system)</span>
					) : null}
				</div>
			</div>
			<div className="box">
				<div className="row">
					<span className="title">Attempts:</span>
					<div className="flex-container">
						<input
							type="number"
							value={numAttempts}
							min="1"
							max="255"
							onChange={event => setNumAttempts(parseInt(event.target.value, 10))}
							onBlur={() => setNumAttempts(Math.max(Math.min(numAttempts, 255), 1))}
						/>
						<HelpButton>
							<div>
								This is the number of tries a student will have to take the assessment quiz. If you
								provide more than one assessment attempt then the final score is determined by the
								&apos;Score Method&apos;. Students will be able to see how many attempts they have
								before they begin the assessment quiz.
							</div>
						</HelpButton>
					</div>
				</div>
				{numAttempts > 1 ? (
					<React.Fragment>
						<div className="row">
							<span className="title">Scoring:</span>
							<div className="flex-container">
								<select
									name="scoringMethod"
									value={scoringMethod}
									onChange={event => setScoringMethod(event.target.value)}
								>
									<option value="highest">Take Highest Attempt</option>
									<option value="average">Take Average Score</option>
									<option value="last">Take Last Attempt</option>
								</select>
								<HelpButton>
									<div>
										This determines how the &apos;Final Score&apos; will be calculated by Obojobo
										for instances with more than one attempt. The student will be able to see how
										their score will be calculated before they begin the assessment quiz.
									</div>
								</HelpButton>
							</div>
						</div>
						{renderExample()}
					</React.Fragment>
				) : null}
				<div className="row">
					<div className="score-import">
						<label onClick={event => setIsImportAllowed(event.target.checked)}>
							<input type="checkbox" name="isImportAllowed" defaultChecked={isImportAllowed} />
							<span>Allow past scores to be imported</span>
						</label>
						<HelpButton>
							<div>
								This option allows students who have already taken this learning object to import
								their past highest attempt score instead of re-taking the object.
							</div>
						</HelpButton>
					</div>
				</div>
				<div className="buttons">
					<Button text="Cancel" type="alt" onClick={props.onCancel} />
					<Button text="Save" type="small" onClick={onSave} />
				</div>
			</div>
		</div>
	)
}

ModalInstanceDetails.defaultProps = {
	instanceName: '',
	courseName: '',
	startDate: null,
	endDate: null,
	numAttempts: 1,
	scoringMethod: 'highest',
	isImportAllowed: true
}

ModalInstanceDetails.propTypes = {
	onCancel: PropTypes.func.isRequired,
	onSave: PropTypes.func.isRequired,
	isExternallyLinked: PropTypes.bool.isRequired,
	instanceName: PropTypes.string,
	courseName: PropTypes.string,
	startDate: PropTypes.oneOfType([null, PropTypes.number]),
	endDate: PropTypes.oneOfType([null, PropTypes.number]),
	numAttempts: PropTypes.number,
	scoringMethod: PropTypes.oneOf(['highest', 'average', 'last']),
	isImportAllowed: PropTypes.bool
}
